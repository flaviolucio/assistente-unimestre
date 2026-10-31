<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Import;

/**
 * Sintaxe DokuWiki (.txt de uma exportação "data/pages") → texto semântico
 * limpo para o RAG. Não gera HTML e não acessa a rede.
 *
 *   ====== Título ======        → título do documento
 *   ===== Seção ===== (e menores) → "## Seção"
 *     * item / - item (recuo 2)  → "- item" / "1. item" (sublistas recuadas)
 *   ^ cab ^ cab ^ / | c | c |    → "cab | cab"
 *   <code>/<file>                → conteúdo literal preservado
 *   **x** //x// __x__ ''x''      → x
 *   [[ns:pagina|rótulo]]         → "rótulo (URL oficial)" se o manifest mapear;
 *                                  senão "rótulo [página DokuWiki: ns:pagina]"
 *   [[https://…|rótulo]]         → "rótulo (https://…)"
 *   {{:ns:arq.pdf|rótulo}}       → "rótulo [arquivo: ns:arq.pdf]" (+ media_refs)
 *   <note>/<WRAP important>      → "Nota:" / "Importante:"
 *   ((nota de rodapé))           → "(nota: …)"
 */
final class DokuWikiContentExtractor implements ContentExtractorInterface
{
    public function format(): string
    {
        return 'dokuwiki';
    }

    /** Heurística conservadora: cabeçalho DokuWiki + ao menos outro marcador típico. */
    public static function looksLikeDokuWiki(string $raw): bool
    {
        if (preg_match('/^[ \t]*={2,6}[^=\n].*?={2,6}[ \t]*$/m', $raw) !== 1) {
            return false;
        }

        return preg_match('/\[\[[^\]]+\]\]|^ {2,}[*-] |^[\^|].*[\^|]\s*$|\*\*[^*\n]+\*\*|<code|<file|\{\{[^}]+\}\}|~~NOTOC~~/m', $raw) === 1;
    }

    /**
     * @param array{dokuwiki_id?: ?string, link_map?: array<string, string>} $context
     */
    public function extract(string $raw, array $context = []): array
    {
        $linkMap = (array) ($context['link_map'] ?? []);
        $pageId = (string) ($context['dokuwiki_id'] ?? '');
        $namespace = str_contains($pageId, ':') ? substr($pageId, 0, (int) strrpos($pageId, ':')) : '';
        $text = str_replace(["\r\n", "\r"], "\n", $raw);
        $text = preg_replace('/~~[A-Z]+~~/', '', $text) ?? $text;

        $title = '';
        $out = [];
        $hosts = [];
        $media = [];
        $internal = [];
        $counters = [];
        $inCode = false;

        foreach (explode("\n", $text) as $line) {
            // Blocos de código/arquivo: conteúdo literal.
            if (preg_match('/^\s*<(code|file)\b[^>]*>(.*)$/i', $line, $m) === 1) {
                $inCode = !preg_match('/<\/(code|file)>/i', $m[2]);
                $rest = preg_replace('/<\/(code|file)>.*$/i', '', $m[2]) ?? '';
                if (trim($rest) !== '') {
                    $out[] = $rest;
                }
                continue;
            }

            if ($inCode) {
                if (preg_match('/^(.*)<\/(code|file)>/i', $line, $m) === 1) {
                    $inCode = false;
                    if (trim($m[1]) !== '') {
                        $out[] = $m[1];
                    }
                } else {
                    $out[] = $line;
                }
                continue;
            }

            // Cabeçalhos: mais "=" = nível mais alto.
            if (preg_match('/^\s*(={2,6})\s*(.+?)\s*={2,6}\s*$/', $line, $m) === 1) {
                $heading = $this->inline($m[2], $namespace, $linkMap, $hosts, $media, $internal);
                $counters = [];

                if ($title === '' && strlen($m[1]) >= 5) {
                    $title = mb_substr($heading, 0, 255);
                    continue;
                }

                $out[] = '';
                $out[] = '## ' . $heading;
                $out[] = '';
                continue;
            }

            // Listas: recuo mínimo de 2 espaços; "*" = marcador, "-" = numerada.
            if (preg_match('/^( {2,}|\t+)([*-])\s+(.*)$/', $line, $m) === 1) {
                $depth = max(0, (int) floor(strlen(str_replace("\t", '  ', $m[1])) / 2) - 1);
                $counters = array_slice($counters, 0, $depth + 1, true);
                $item = $this->inline($m[3], $namespace, $linkMap, $hosts, $media, $internal);

                if ($m[2] === '-') {
                    $counters[$depth] = ($counters[$depth] ?? 0) + 1;
                    $out[] = str_repeat('  ', $depth) . $counters[$depth] . '. ' . $item;
                } else {
                    $out[] = str_repeat('  ', $depth) . '- ' . $item;
                }
                continue;
            }

            $counters = [];

            // Tabelas.
            if (preg_match('/^\s*[\^|].*[\^|]\s*$/', $line) === 1) {
                $cells = preg_split('/[\^|]/', trim(trim($line), '^|')) ?: [];
                $cells = array_map(fn ($c) => $this->inline(trim($c), $namespace, $linkMap, $hosts, $media, $internal), $cells);
                $out[] = implode(' | ', array_filter($cells, static fn ($c) => $c !== '' && $c !== ':::'));
                continue;
            }

            // Texto pré-formatado (recuo de 2 espaços sem marcador de lista).
            if (preg_match('/^ {2,}\S/', $line) === 1) {
                $out[] = trim($line);
                continue;
            }

            if (preg_match('/^\s*-{4,}\s*$/', $line) === 1) {
                $out[] = '';
                continue;
            }

            $out[] = $this->inline($line, $namespace, $linkMap, $hosts, $media, $internal);
        }

        $content = implode("\n", $out);
        $content = preg_replace("/[ \t]+\n/", "\n", $content) ?? $content;
        $content = trim(preg_replace("/\n{3,}/", "\n\n", $content) ?? $content);

        return TextContentExtractor::normalized($title, $content, [
            'hosts' => array_keys($hosts),
            'media_refs' => array_keys($media),
            'metadata' => [
                'dokuwiki_id' => $pageId !== '' ? $pageId : null,
                'internal_links' => array_keys($internal),
            ],
        ]);
    }

    private function inline(string $text, string $namespace, array $linkMap, array &$hosts, array &$media, array &$internal): string
    {
        // Notas e avisos (plugins note/wrap).
        $text = preg_replace('/<note\s+(important|warning)>/i', 'Importante: ', $text) ?? $text;
        $text = preg_replace('/<note\s+tip>/i', 'Dica: ', $text) ?? $text;
        $text = preg_replace('/<note[^>]*>/i', 'Nota: ', $text) ?? $text;
        $text = preg_replace('/<WRAP[^>]*\b(important|alert|danger|warning)\b[^>]*>/i', 'Importante: ', $text) ?? $text;
        $text = preg_replace('/<WRAP[^>]*\b(info|tip|help)\b[^>]*>/i', 'Nota: ', $text) ?? $text;
        $text = preg_replace('/<\/?(note|WRAP|wrap|del|sub|sup|nowiki)[^>]*>/i', '', $text) ?? $text;

        // Mídia {{:ns:arquivo.pdf|rótulo}} / {{ imagem.png }}
        $text = preg_replace_callback('/\{\{\s*([^}|]+?)\s*(?:\|([^}]*))?\}\}/', function (array $m) use (&$media, $namespace): string {
            $target = preg_replace('/\?.*$/', '', trim($m[1])) ?? trim($m[1]);
            $label = trim($m[2] ?? '');

            if (preg_match('#^https?://#i', $target) === 1) {
                return $label !== '' ? $label : '';
            }

            $id = $this->resolveId($target, $namespace);
            $media[$id] = true;

            if (preg_match('/\.(png|jpe?g|gif|webp|svg|bmp)$/i', $target) === 1) {
                return $label !== '' ? '[imagem: ' . $label . ']' : '';
            }

            return ($label !== '' ? $label . ' ' : '') . '[arquivo: ' . $id . ']';
        }, $text) ?? $text;

        // Links [[alvo|rótulo]]
        $text = preg_replace_callback('/\[\[\s*([^\]|]+?)\s*(?:\|([^\]]*))?\]\]/', function (array $m) use (&$hosts, &$internal, $linkMap, $namespace): string {
            $target = trim($m[1]);
            $label = trim($m[2] ?? '');

            if (preg_match('#^https?://#i', $target) === 1) {
                $host = strtolower((string) parse_url($target, PHP_URL_HOST));
                if ($host !== '') {
                    $hosts[$host] = true;
                }

                return $label !== '' && $label !== $target ? $label . ' (' . $target . ')' : $target;
            }

            [$page, $anchor] = array_pad(explode('#', $target, 2), 2, '');
            $id = $this->resolveId($page !== '' ? $page : $namespace, $namespace);
            $internal[$id] = true;
            $label = $label !== '' ? $label : ($anchor !== '' ? $anchor : (string) substr($id, (int) strrpos($id, ':') + (str_contains($id, ':') ? 1 : 0)));

            return isset($linkMap[$id])
                ? $label . ' (' . $linkMap[$id] . ')'
                : $label . ' [página DokuWiki: ' . $id . ']';
        }, $text) ?? $text;

        // Rodapé ((nota))
        $text = preg_replace('/\(\((.+?)\)\)/', ' (nota: $1)', $text) ?? $text;
        // Ênfases: **x**, __x__, ''x'', //x// (sem tocar em "https://")
        $text = preg_replace('/\*\*(.+?)\*\*/', '$1', $text) ?? $text;
        $text = preg_replace('/__(.+?)__/', '$1', $text) ?? $text;
        $text = preg_replace("/''(.+?)''/", '$1', $text) ?? $text;
        $text = preg_replace('#(?<![:/\w])//(?!\s)(.+?)(?<![\s:])//(?!\w)#', '$1', $text) ?? $text;
        // Quebra forçada "\\ "
        $text = preg_replace('/\\\\\\\\(\s|$)/', "\n", $text) ?? $text;

        return rtrim($text);
    }

    /** "pagina" relativa ao namespace atual; ":ns:pagina" absoluta; minúsculas. */
    private function resolveId(string $target, string $namespace): string
    {
        $target = trim(str_replace('/', ':', $target));

        if (str_starts_with($target, ':')) {
            $id = ltrim($target, ':');
        } elseif (str_starts_with($target, '.')) {
            $id = ($namespace !== '' ? $namespace . ':' : '') . ltrim($target, '.:');
        } elseif (!str_contains($target, ':') && $namespace !== '') {
            $id = $namespace . ':' . $target;
        } else {
            $id = $target;
        }

        return mb_strtolower(trim($id, ':'));
    }
}
