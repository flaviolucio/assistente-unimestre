<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Import;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Extração DETERMINÍSTICA do conteúdo útil de uma página HTML salva
 * (sem IA, sem rede). Não resume nem reescreve: só remove o ruído e converte
 * a estrutura para texto.
 *
 * Preserva: título, subtítulos (##), parágrafos, listas (- / 1.), tabelas
 * (células separadas por " | "), avisos e links (texto + URL quando a URL
 * é http(s) e difere do texto).
 * Remove: script, style, noscript, iframe, svg, form, nav, header, footer,
 * aside, banners de cookie/consentimento, menus, breadcrumbs, compartilhamento,
 * comentários e "relacionados".
 */
final class HtmlContentExtractor implements ContentExtractorInterface
{
    public function format(): string
    {
        return 'html';
    }

    private const NOISE_TAGS = ['script', 'style', 'noscript', 'iframe', 'svg', 'form', 'nav', 'header', 'footer', 'aside', 'button', 'template', 'object', 'embed', 'canvas', 'video', 'audio'];

    private const NOISE_PATTERN = '/(^|[\s_-])(cookie|consent|gdpr|lgpd|banner|menu|navbar|nav|sidebar|widget|breadcrumbs?|share|sharing|social|comment|comments|related|footer|header|newsletter|popup|modal|advert|ads?|skip-link|screen-reader)([\s_-]|$)/i';

    private const CONTENT_XPATHS = [
        '//*[@itemprop="articleBody"]',
        '//article//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]',
        '//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]',
        '//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]',
        '//*[contains(concat(" ", normalize-space(@class), " "), " article-content ")]',
        '//*[contains(concat(" ", normalize-space(@class), " "), " wiki-content ")]',
        '//*[@id="dokuwiki__content"]',
        '//article',
        '//main',
        '//*[@id="content"]',
        '//*[@role="main"]',
    ];

    /**
     * @return array<string, mixed> documento normalizado (ver ContentExtractorInterface)
     */
    public function extract(string $html, array $context = []): array
    {
        $savedFrom = null;

        if (preg_match('/<!--\s*saved from url=\(\d+\)(\S+?)\s*-->/i', $html, $m) === 1) {
            $savedFrom = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // Força UTF-8; LIBXML_NONET impede qualquer acesso de rede do parser.
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);

        [$url, $urlSource] = $this->officialUrl($xpath, $savedFrom);
        $category = $this->category($xpath);
        $title = $this->title($xpath);
        $hosts = $this->linkHosts($xpath);
        $dates = $this->sourceDates($xpath);

        $root = $this->contentRoot($xpath);
        $content = '';

        if ($root !== null) {
            $this->removeNoise($root);
            $content = $this->normalizeText($this->toText($root));

            // Título duplicado como primeira linha do corpo é removido.
            if ($title !== '' && str_starts_with(ltrim($content, "# \n"), $title)) {
                $content = trim(preg_replace('/^#*\s*' . preg_quote($title, '/') . '\s*\n?/u', '', $content, 1) ?? $content);
            }
        }

        return [
            'title' => $title,
            'content' => $content,
            'url' => $url,
            'url_source' => $urlSource,
            'category' => $category,
            'subcategory' => null,
            'published_at' => $dates['published_at'],
            'source_modified_at' => $dates['source_modified_at'],
            'source_modified_type' => $dates['source_modified_type'],
            'hosts' => $hosts,
            'media_refs' => [],
            'metadata' => [],
            'quality' => null,
        ];
    }

    /**
     * Datas declaradas PELA FONTE (nunca inferidas):
     *   meta article:published_time / article:modified_time (WordPress)
     *   "Última modificação: 03/10/2025 10:50 (edição externa)" (DokuWiki)
     * "edição externa" NÃO vira data de publicação.
     *
     * @return array{published_at: ?string, source_modified_at: ?string, source_modified_type: ?string}
     */
    private function sourceDates(DOMXPath $xpath): array
    {
        $published = $this->isoDate((string) ($xpath->query('//meta[@property="article:published_time"]/@content')->item(0)?->nodeValue ?? ''));
        $modified = $this->isoDate((string) ($xpath->query('//meta[@property="article:modified_time"]/@content')->item(0)?->nodeValue ?? ''));
        $type = $modified !== null ? 'edit' : null;
        $text = (string) ($xpath->query('//body')->item(0)?->textContent ?? '');

        if (preg_match('/(?:[ÚU]ltima modifica[cç][aã]o|Last modified)\s*:?\s*(\d{2})\/(\d{2})\/(\d{4})(?:\s+(\d{2}):(\d{2}))?\s*(\((?:edi[cç][aã]o externa|external edit)\))?/iu', $text, $m) === 1) {
            $modified = sprintf('%s-%s-%s %s:%s:00', $m[3], $m[2], $m[1], $m[4] !== '' ? $m[4] : '00', ($m[5] ?? '') !== '' ? $m[5] : '00');
            $type = !empty($m[6]) ? 'external_edit' : 'edit';
        }

        return ['published_at' => $published, 'source_modified_at' => $modified, 'source_modified_type' => $type];
    }

    private function isoDate(string $value): ?string
    {
        $value = trim($value);
        // Hora "de parede" como a fonte publicou (sem converter fuso).
        try {
            return $value !== '' ? (new \DateTimeImmutable($value))->format('Y-m-d H:i:s') : null;
        } catch (\Exception) {
            return null;
        }
    }

    /** @return array{0: ?string, 1: ?string} */
    private function officialUrl(DOMXPath $xpath, ?string $savedFrom): array
    {
        foreach ([
            'canonical' => '//link[translate(@rel, "CANONICAL", "canonical")="canonical"]/@href',
            'og:url' => '//meta[@property="og:url"]/@content',
        ] as $source => $query) {
            $value = trim((string) ($xpath->query($query)->item(0)?->nodeValue ?? ''));

            if ($value !== '' && preg_match('#^https?://#i', $value) === 1) {
                return [$value, $source];
            }
        }

        return $savedFrom !== null && preg_match('#^https?://#i', $savedFrom) === 1 ? [$savedFrom, 'saved_from'] : [null, null];
    }

    /** Categoria da PRÓPRIA página (taxonomia oficial), sem inventar. */
    private function category(DOMXPath $xpath): ?string
    {
        $queries = [
            '//meta[@property="article:section"]/@content',
            '//a[@rel="category tag"]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " cat-links ")]//a',
            '//*[contains(@class, "breadcrumb")]//a[position() = last()]',
        ];

        foreach ($queries as $query) {
            $node = $xpath->query($query)->item(0);
            $value = trim(preg_replace('/\s+/u', ' ', (string) ($node?->nodeValue ?? '')) ?? '');

            if ($value !== '' && mb_strlen($value) <= 150 && !in_array(mb_strtolower($value), ['início', 'inicio', 'home', 'base de conhecimento'], true)) {
                return $value;
            }
        }

        return null;
    }

    private function title(DOMXPath $xpath): string
    {
        foreach (['//article//h1', '//main//h1', '//h1', '//meta[@property="og:title"]/@content', '//title'] as $query) {
            $value = trim(preg_replace('/\s+/u', ' ', (string) ($xpath->query($query)->item(0)?->nodeValue ?? '')) ?? '');

            if ($value !== '') {
                // "Artigo – UNIMESTRE" / "Artigo | Base de Conhecimento"
                $value = trim(preg_replace('/\s*[|–—-]\s*(unimestre|base de conhecimento)[^|–—-]*$/iu', '', $value) ?? $value);

                return mb_substr($value, 0, 255);
            }
        }

        return '';
    }

    /** @return array<int, string> */
    private function linkHosts(DOMXPath $xpath): array
    {
        $hosts = [];

        foreach ($xpath->query('//a/@href') as $href) {
            $host = strtolower((string) parse_url(trim((string) $href->nodeValue), PHP_URL_HOST));

            if ($host !== '') {
                $hosts[$host] = true;
            }
        }

        return array_keys($hosts);
    }

    private function contentRoot(DOMXPath $xpath): ?DOMElement
    {
        foreach (self::CONTENT_XPATHS as $query) {
            $node = $xpath->query($query)->item(0);

            if ($node instanceof DOMElement && trim($node->textContent) !== '') {
                return $node;
            }
        }

        $body = $xpath->query('//body')->item(0);

        return $body instanceof DOMElement ? $body : null;
    }

    private function removeNoise(DOMElement $root): void
    {
        $remove = [];
        $walker = static function (DOMNode $node) use (&$walker, &$remove): void {
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $tag = strtolower($child->tagName);
                    $marker = $child->getAttribute('class') . ' ' . $child->getAttribute('id') . ' ' . $child->getAttribute('role');

                    if (in_array($tag, self::NOISE_TAGS, true)
                        || preg_match(self::NOISE_PATTERN, $marker) === 1
                        || $child->getAttribute('aria-hidden') === 'true'
                        || preg_match('/display\s*:\s*none/i', $child->getAttribute('style')) === 1) {
                        $remove[] = $child;
                        continue;
                    }

                    $walker($child);
                } elseif ($child->nodeType === XML_COMMENT_NODE) {
                    $remove[] = $child;
                }
            }
        };

        $walker($root);

        foreach ($remove as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private function toText(DOMNode $node, int $listDepth = 0): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $out .= preg_replace('/\s+/u', ' ', $child->nodeValue ?? '') ?? '';
                continue;
            }

            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            switch ($tag) {
                case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                    $text = trim($this->inline($child));
                    $out .= $text !== '' ? "\n\n## " . $text . "\n\n" : '';
                    break;
                case 'p': case 'blockquote': case 'pre': case 'section': case 'div': case 'figure': case 'dl':
                    $out .= "\n\n" . $this->toText($child, $listDepth) . "\n\n";
                    break;
                case 'br':
                    $out .= "\n";
                    break;
                case 'hr':
                    $out .= "\n\n";
                    break;
                case 'ul': case 'ol':
                    $out .= "\n" . $this->listToText($child, $listDepth) . "\n";
                    break;
                case 'table':
                    $out .= "\n\n" . $this->tableToText($child) . "\n\n";
                    break;
                case 'a':
                    $out .= $this->linkText($child);
                    break;
                case 'img':
                    $alt = trim($child->getAttribute('alt'));
                    $out .= $alt !== '' ? ' [imagem: ' . $alt . '] ' : '';
                    break;
                default:
                    $out .= $this->toText($child, $listDepth);
            }
        }

        return $out;
    }

    private function inline(DOMNode $node): string
    {
        return trim(preg_replace('/\s+/u', ' ', $this->toText($node)) ?? '');
    }

    private function linkText(DOMElement $a): string
    {
        $text = $this->inline($a);
        $href = trim($a->getAttribute('href'));

        if ($href !== '' && preg_match('#^https?://#i', $href) === 1 && $text !== '' && $text !== $href) {
            return $text . ' (' . $href . ')';
        }

        return $text !== '' ? $text : '';
    }

    private function listToText(DOMElement $list, int $depth): string
    {
        $lines = [];
        $ordered = strtolower($list->tagName) === 'ol';
        $n = 1;

        foreach ($list->childNodes as $item) {
            if (!$item instanceof DOMElement || strtolower($item->tagName) !== 'li') {
                continue;
            }

            $nested = '';
            $clone = $item->cloneNode(true);

            foreach (iterator_to_array($clone->childNodes) as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['ul', 'ol'], true)) {
                    $nested .= "\n" . $this->listToText($child, $depth + 1);
                    $clone->removeChild($child);
                }
            }

            $text = trim(preg_replace('/\s+/u', ' ', $this->toText($clone, $depth + 1)) ?? '');

            if ($text !== '') {
                $lines[] = str_repeat('  ', $depth) . ($ordered ? $n++ . '. ' : '- ') . $text . $nested;
            }
        }

        return implode("\n", $lines);
    }

    private function tableToText(DOMElement $table): string
    {
        $rows = [];

        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];

            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    $cells[] = $this->inline($cell);
                }
            }

            if (array_filter($cells, static fn ($c) => $c !== '') !== []) {
                $rows[] = implode(' | ', $cells);
            }
        }

        return implode("\n", $rows);
    }

    private function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        // Colapsa espaços internos, preservando o recuo inicial (sublistas).
        $lines = array_map(static fn ($line) => rtrim(preg_replace('/(?<=\S)[ \t]+/u', ' ', $line) ?? $line), explode("\n", $text));
        $lines = array_map(static fn ($line) => preg_match('/^\s*(-|\d+\.)\s/', $line) === 1 ? $line : ltrim($line), $lines);
        $text = implode("\n", $lines);
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
