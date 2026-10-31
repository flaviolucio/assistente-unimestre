<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Import;

/**
 * Extração de TEXTO de PDF em PHP puro (sem binários externos, sem
 * dependências, sem rede, sem OCR). Compatível com XAMPP e hospedagem
 * compartilhada (usa apenas zlib).
 *
 * Suporta PDFs com camada de texto: objetos diretos e object streams
 * (PDF 1.5+), FlateDecode, fontes simples (WinAnsi) e fontes com ToUnicode
 * (inclusive Identity-H de 2 bytes).
 *
 * Qualidade (nunca inventa conteúdo):
 *   text         texto útil extraído
 *   ocr_needed   páginas sem camada de texto (digitalizado/imagem)
 *   failed       estrutura não reconhecida ou texto corrompido/ilegível
 *   unsupported  PDF cifrado/protegido
 */
final class PdfContentExtractor implements ContentExtractorInterface
{
    public const EXTRACTOR = 'php-native-pdf-v1';

    private const MIN_TEXT_CHARS = 40;
    private const MIN_PRINTABLE_RATIO = 0.90;
    private const MIN_LETTER_RATIO = 0.45;

    /** @var array<int, array{dict: string, stream: ?string}> */
    private array $objects = [];

    /** @var array<int, ?string> */
    private array $decoded = [];

    /** @var array<int, array{map: array<int, string>, len: int}|null> */
    private array $cmaps = [];

    public function format(): string
    {
        return 'pdf';
    }

    public function extract(string $raw, array $context = []): array
    {
        $this->objects = [];
        $this->decoded = [];
        $this->cmaps = [];
        $meta = ['pdf_extractor' => self::EXTRACTOR, 'pdf_text_extracted' => false, 'pdf_pages' => 0];

        if (!str_contains(substr($raw, 0, 1024), '%PDF-')) {
            return $this->result('', '', 'failed', $meta + ['pdf_error' => 'Arquivo não é um PDF válido.']);
        }

        if (preg_match('/\/Encrypt\s*(\d+\s+\d+\s+R|<<)/', $raw) === 1) {
            return $this->result('', '', 'unsupported', $meta + ['pdf_error' => 'PDF protegido/cifrado.']);
        }

        try {
            $this->parseObjects($raw);
            $pages = $this->pageObjects($raw);
            $meta['pdf_pages'] = count($pages);

            if ($pages === []) {
                return $this->result('', '', 'failed', $meta + ['pdf_error' => 'Nenhuma página reconhecida na estrutura do PDF.']);
            }

            $globalFonts = $this->globalFonts();
            $texts = [];
            $emptyPages = 0;

            foreach ($pages as $pageNum) {
                $pageText = $this->pageText($pageNum, $globalFonts);
                $texts[] = $pageText;

                if (mb_strlen(preg_replace('/\s+/u', '', $pageText) ?? '') < 5) {
                    $emptyPages++;
                }
            }
        } catch (\Throwable $exception) {
            return $this->result('', '', 'failed', $meta + ['pdf_error' => 'Falha ao interpretar o PDF.']);
        }

        $content = $this->cleanup(implode("\n\n", $texts));
        $meta['pdf_pages_without_text'] = $emptyPages;
        $compact = preg_replace('/\s+/u', '', $content) ?? '';
        $length = mb_strlen($compact);

        if ($length < self::MIN_TEXT_CHARS) {
            return $this->result($this->infoTitle($raw), '', 'ocr_needed', $meta + ['pdf_error' => 'Sem camada de texto útil (provável PDF digitalizado).']);
        }

        $printable = preg_match_all('/[\p{L}\p{N}\p{P}\p{S}]/u', $compact);
        $letters = preg_match_all('/\p{L}/u', $compact);
        $meta['pdf_printable_ratio'] = round($printable / max(1, $length), 3);

        if ($printable / max(1, $length) < self::MIN_PRINTABLE_RATIO || $letters / max(1, $length) < self::MIN_LETTER_RATIO) {
            return $this->result($this->infoTitle($raw), '', 'failed', $meta + ['pdf_error' => 'Texto extraído corrompido ou com codificação não suportada.']);
        }

        $meta['pdf_text_extracted'] = true;
        $title = $this->infoTitle($raw);

        if ($title === '' && preg_match('/^\s*(\S.{2,120}?)\s*$/mu', $content, $m) === 1) {
            $title = trim($m[1]);
        }

        return $this->result($title, $content, 'text', $meta);
    }

    private function result(string $title, string $content, string $quality, array $meta): array
    {
        return TextContentExtractor::normalized($title, $content, ['quality' => $quality, 'metadata' => $meta]);
    }

    // ------------------------------------------------------------------ estrutura

    private function parseObjects(string $raw): void
    {
        if (preg_match_all('/(\d+)\s+(\d+)\s+obj\b/', $raw, $matches, PREG_OFFSET_CAPTURE) === false) {
            return;
        }

        foreach ($matches[0] as $i => $match) {
            $num = (int) $matches[1][$i][0];
            $start = $match[1] + strlen($match[0]);
            $end = strpos($raw, 'endobj', $start);

            if ($end === false) {
                continue;
            }

            $body = substr($raw, $start, $end - $start);
            $streamPos = strpos($body, 'stream');
            $stream = null;
            $dict = $body;

            if ($streamPos !== false && preg_match('/^\s*<</', $body) === 1) {
                $dict = substr($body, 0, $streamPos);
                $dataStart = $streamPos + 6;
                $dataStart += ($body[$dataStart] ?? '') === "\r" ? 2 : 1;
                $length = preg_match('/\/Length\s+(\d+)(?!\s+\d+\s+R)/', $dict, $lm) === 1 ? (int) $lm[1] : null;
                $endStream = strrpos($body, 'endstream');

                if ($length !== null && $dataStart + $length <= strlen($body)) {
                    $stream = substr($body, $dataStart, $length);
                } elseif ($endStream !== false) {
                    $stream = rtrim(substr($body, $dataStart, $endStream - $dataStart), "\r\n");
                }
            }

            $this->objects[$num] = ['dict' => trim($dict), 'stream' => $stream];
        }

        // Object streams (PDF 1.5+): objetos comprimidos dentro de /Type /ObjStm.
        foreach ($this->objects as $num => $obj) {
            if ($obj['stream'] === null || preg_match('/\/Type\s*\/ObjStm/', $obj['dict']) !== 1) {
                continue;
            }

            $data = $this->stream($num);

            if ($data === null || preg_match('/\/N\s+(\d+)/', $obj['dict'], $n) !== 1 || preg_match('/\/First\s+(\d+)/', $obj['dict'], $f) !== 1) {
                continue;
            }

            $header = preg_split('/\s+/', trim(substr($data, 0, (int) $f[1]))) ?: [];
            $count = min((int) $n[1], intdiv(count($header), 2));

            for ($k = 0; $k < $count; $k++) {
                $objNum = (int) $header[$k * 2];
                $offset = (int) $f[1] + (int) $header[$k * 2 + 1];
                $next = $k + 1 < $count ? (int) $f[1] + (int) $header[($k + 1) * 2 + 1] : strlen($data);

                if (!isset($this->objects[$objNum])) {
                    $this->objects[$objNum] = ['dict' => trim(substr($data, $offset, $next - $offset)), 'stream' => null];
                }
            }
        }
    }

    private function stream(int $num): ?string
    {
        if (array_key_exists($num, $this->decoded)) {
            return $this->decoded[$num];
        }

        $obj = $this->objects[$num] ?? null;
        $data = $obj['stream'] ?? null;

        if ($data === null) {
            return $this->decoded[$num] = null;
        }

        $filters = [];

        if (preg_match('/\/Filter\s*\[([^\]]*)\]/', $obj['dict'], $m) === 1) {
            preg_match_all('/\/(\w+)/', $m[1], $fm);
            $filters = $fm[1];
        } elseif (preg_match('/\/Filter\s*\/(\w+)/', $obj['dict'], $m) === 1) {
            $filters = [$m[1]];
        }

        foreach ($filters as $filter) {
            if ($filter !== 'FlateDecode' && $filter !== 'Fl') {
                return $this->decoded[$num] = null; // imagens (DCT etc.) e filtros não suportados
            }

            $out = @gzuncompress($data);
            $out = $out !== false ? $out : @gzinflate(substr($data, 2));

            if ($out === false) {
                return $this->decoded[$num] = null;
            }

            $data = $out;
        }

        return $this->decoded[$num] = $data;
    }

    /** @return array<int, int> números dos objetos /Page em ordem de leitura */
    private function pageObjects(string $raw): array
    {
        $pages = [];
        $root = null;

        foreach ($this->objects as $num => $obj) {
            if (preg_match('/\/Type\s*\/Pages\b/', $obj['dict']) === 1 && preg_match('/\/Parent\s+\d+/', $obj['dict']) !== 1) {
                $root = $num;
                break;
            }
        }

        if ($root !== null) {
            $this->collectKids($root, $pages, 0);
        }

        if ($pages === []) {
            foreach ($this->objects as $num => $obj) {
                if (preg_match('/\/Type\s*\/Page(?!s)\b/', $obj['dict']) === 1) {
                    $pages[] = $num;
                }
            }
        }

        return $pages;
    }

    private function collectKids(int $num, array &$pages, int $depth): void
    {
        $dict = $this->objects[$num]['dict'] ?? '';

        if ($depth > 50) {
            return;
        }

        if (preg_match('/\/Type\s*\/Page(?!s)\b/', $dict) === 1) {
            $pages[] = $num;

            return;
        }

        if (preg_match('/\/Kids\s*\[([^\]]*)\]/', $dict, $m) === 1 && preg_match_all('/(\d+)\s+\d+\s+R/', $m[1], $refs) > 0) {
            foreach ($refs[1] as $ref) {
                $this->collectKids((int) $ref, $pages, $depth + 1);
            }
        }
    }

    // ------------------------------------------------------------------ fontes

    /** @return array<string, int> nome do recurso → objeto da fonte (qualquer página) */
    private function globalFonts(): array
    {
        $fonts = [];

        foreach ($this->objects as $obj) {
            foreach ($this->fontEntries($obj['dict']) as $name => $ref) {
                $fonts[$name] ??= $ref;
            }
        }

        return $fonts;
    }

    /** @return array<string, int> */
    private function fontEntries(string $dict): array
    {
        $fontDict = $this->dictValue($dict, 'Font');

        if ($fontDict === null) {
            return [];
        }

        if (preg_match('/^(\d+)\s+\d+\s+R$/', trim($fontDict), $m) === 1) {
            $fontDict = $this->objects[(int) $m[1]]['dict'] ?? '';
        }

        $entries = [];

        if (preg_match_all('/\/([^\s\/<>\[\]()]+)\s+(\d+)\s+\d+\s+R/', $fontDict, $mm, PREG_SET_ORDER) > 0) {
            foreach ($mm as $e) {
                $entries[$e[1]] = (int) $e[2];
            }
        }

        return $entries;
    }

    /** @return array{map: array<int, string>, len: int}|null */
    private function fontCmap(int $fontNum): ?array
    {
        if (array_key_exists($fontNum, $this->cmaps)) {
            return $this->cmaps[$fontNum];
        }

        $dict = $this->objects[$fontNum]['dict'] ?? '';
        $cmap = null;

        if (preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $dict, $m) === 1) {
            $data = $this->stream((int) $m[1]);
            $cmap = $data !== null ? $this->parseCmap($data) : null;
        }

        if ($cmap === null && preg_match('/\/Subtype\s*\/Type0/', $dict) === 1) {
            $cmap = ['map' => [], 'len' => 2];
        }

        return $this->cmaps[$fontNum] = $cmap;
    }

    /** @return array{map: array<int, string>, len: int} */
    private function parseCmap(string $data): array
    {
        $map = [];
        $len = 1;

        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $data, $blocks) > 0) {
            foreach ($blocks[1] as $block) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $pairs, PREG_SET_ORDER);
                foreach ($pairs as $p) {
                    $len = max($len, intdiv(strlen($p[1]), 2));
                    $map[hexdec($p[1])] = $this->utf16($p[2]);
                }
            }
        }

        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $data, $blocks) > 0) {
            foreach ($blocks[1] as $block) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]+>|\[[^\]]*\])/', $block, $ranges, PREG_SET_ORDER);
                foreach ($ranges as $r) {
                    $lo = hexdec($r[1]);
                    $hi = min(hexdec($r[2]), $lo + 65535);
                    $len = max($len, intdiv(strlen($r[1]), 2));

                    if ($r[3][0] === '[') {
                        preg_match_all('/<([0-9A-Fa-f]+)>/', $r[3], $dsts);
                        foreach ($dsts[1] as $k => $dst) {
                            $map[$lo + $k] = $this->utf16($dst);
                        }
                    } else {
                        $base = hexdec(trim($r[3], '<>'));
                        $width = strlen(trim($r[3], '<>'));
                        for ($c = $lo; $c <= $hi; $c++) {
                            $map[$c] = $this->utf16(str_pad(dechex($base + ($c - $lo)), $width, '0', STR_PAD_LEFT));
                        }
                    }
                }
            }
        }

        return ['map' => $map, 'len' => $len];
    }

    private function utf16(string $hex): string
    {
        $bin = @hex2bin(strlen($hex) % 2 === 1 ? '0' . $hex : $hex);

        return $bin === false ? '' : (string) mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
    }

    // ------------------------------------------------------------------ texto

    private function pageText(int $pageNum, array $globalFonts): string
    {
        $dict = $this->objects[$pageNum]['dict'] ?? '';
        $resources = $this->dictValue($dict, 'Resources');

        if ($resources !== null && preg_match('/^(\d+)\s+\d+\s+R$/', trim($resources), $m) === 1) {
            $resources = $this->objects[(int) $m[1]]['dict'] ?? '';
        }

        $fonts = array_merge($globalFonts, $resources !== null ? $this->fontEntries($resources) : []);
        $contents = $this->dictValue($dict, 'Contents') ?? '';
        $streams = '';

        if (preg_match_all('/(\d+)\s+\d+\s+R/', $contents, $refs) > 0) {
            foreach ($refs[1] as $ref) {
                $refDict = $this->objects[(int) $ref]['dict'] ?? '';

                // /Contents pode apontar para um array indireto de streams.
                if (($this->objects[(int) $ref]['stream'] ?? null) === null && preg_match_all('/(\d+)\s+\d+\s+R/', $refDict, $inner) > 0) {
                    foreach ($inner[1] as $innerRef) {
                        $streams .= "\n" . ($this->stream((int) $innerRef) ?? '');
                    }
                    continue;
                }

                $streams .= "\n" . ($this->stream((int) $ref) ?? '');
            }
        }

        return $this->interpret($streams, $fonts);
    }

    /** Interpreta os operadores de texto do content stream. */
    private function interpret(string $s, array $fonts): string
    {
        $out = '';
        $stack = [];
        $font = null;
        $lastY = null;
        $n = strlen($s);
        $i = 0;

        while ($i < $n) {
            $c = $s[$i];

            if (ctype_space($c)) {
                $i++;
                continue;
            }

            if ($c === '%') {
                $i = ($p = strpos($s, "\n", $i)) === false ? $n : $p;
                continue;
            }

            if ($c === '(') {
                [$str, $i] = $this->literal($s, $i);
                $stack[] = ['str', $str];
                continue;
            }

            if ($c === '<' && ($s[$i + 1] ?? '') !== '<') {
                $end = strpos($s, '>', $i);
                $end = $end === false ? $n : $end;
                $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($s, $i + 1, $end - $i - 1)) ?? '';
                $stack[] = ['str', (string) @hex2bin(strlen($hex) % 2 ? $hex . '0' : $hex)];
                $i = $end + 1;
                continue;
            }

            if ($c === '[') {
                $stack[] = ['[', null];
                $i++;
                continue;
            }

            if ($c === ']') {
                $arr = [];
                while ($stack !== [] && end($stack)[0] !== '[') {
                    array_unshift($arr, array_pop($stack));
                }
                array_pop($stack);
                $stack[] = ['arr', $arr];
                $i++;
                continue;
            }

            if ($c === '/') {
                preg_match('/\/[^\s\/<>\[\]()%{}]*/A', $s, $m, 0, $i);
                $stack[] = ['name', substr($m[0], 1)];
                $i += strlen($m[0]);
                continue;
            }

            if (preg_match('/[-+]?(\d+\.?\d*|\.\d+)/A', $s, $m, 0, $i) === 1) {
                $stack[] = ['num', (float) $m[0]];
                $i += strlen($m[0]);
                continue;
            }

            if (preg_match('/[A-Za-z\'"*]+/A', $s, $m, 0, $i) === 1) {
                $op = $m[0];
                $i += strlen($op);

                if ($op === 'BI') { // imagem inline: pula até EI
                    $ei = strpos($s, 'EI', $i);
                    $i = $ei === false ? $n : $ei + 2;
                    $stack = [];
                    continue;
                }

                switch ($op) {
                    case 'Tf':
                        $name = $stack[count($stack) - 2][1] ?? null;
                        $font = is_string($name) ? ($fonts[$name] ?? null) : null;
                        break;
                    case 'Tj':
                        $out .= $this->decode((string) (end($stack)[1] ?? ''), $font);
                        break;
                    case "'":
                    case '"':
                        $out .= "\n" . $this->decode((string) (end($stack)[1] ?? ''), $font);
                        break;
                    case 'TJ':
                        foreach ((array) (end($stack)[1] ?? []) as $part) {
                            if ($part[0] === 'str') {
                                $out .= $this->decode((string) $part[1], $font);
                            } elseif ($part[0] === 'num' && $part[1] < -180) {
                                $out .= ' ';
                            }
                        }
                        break;
                    case 'Td':
                    case 'TD':
                        $ty = (float) ($stack[count($stack) - 1][1] ?? 0);
                        $tx = (float) ($stack[count($stack) - 2][1] ?? 0);
                        $out .= abs($ty) > 0.1 ? "\n" : ($tx > 0.5 ? ' ' : '');
                        break;
                    case 'Tm':
                        $y = (float) ($stack[count($stack) - 1][1] ?? 0);
                        $out .= $lastY !== null && abs($y - $lastY) > 0.1 ? "\n" : ' ';
                        $lastY = $y;
                        break;
                    case 'T*':
                        $out .= "\n";
                        break;
                    case 'ET':
                        $out .= "\n";
                        break;
                }

                $stack = [];
                continue;
            }

            $i++; // caractere inesperado: ignora
        }

        return $out;
    }

    /** @return array{0: string, 1: int} */
    private function literal(string $s, int $i): array
    {
        $depth = 0;
        $out = '';
        $n = strlen($s);
        $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C", '(' => '(', ')' => ')', '\\' => '\\'];

        for ($j = $i; $j < $n; $j++) {
            $c = $s[$j];

            if ($c === '\\') {
                $next = $s[$j + 1] ?? '';

                if (isset($map[$next])) {
                    $out .= $map[$next];
                    $j++;
                } elseif (preg_match('/[0-7]{1,3}/A', $s, $m, 0, $j + 1) === 1) {
                    $out .= chr(octdec($m[0]) & 0xFF);
                    $j += strlen($m[0]);
                } elseif ($next === "\n" || $next === "\r") {
                    $j++;
                }
                continue;
            }

            if ($c === '(') {
                if ($depth++ > 0) {
                    $out .= $c;
                }
                continue;
            }

            if ($c === ')') {
                if (--$depth === 0) {
                    return [$out, $j + 1];
                }
                $out .= $c;
                continue;
            }

            $out .= $c;
        }

        return [$out, $n];
    }

    private function decode(string $bytes, ?int $font): string
    {
        $cmap = $font !== null ? $this->fontCmap($font) : null;

        if ($cmap !== null) {
            $out = '';
            $len = max(1, $cmap['len']);

            for ($k = 0, $n = strlen($bytes); $k + $len <= $n; $k += $len) {
                $code = hexdec(bin2hex(substr($bytes, $k, $len)));
                $out .= $cmap['map'][$code] ?? '';
            }

            return $out;
        }

        return (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    // ------------------------------------------------------------------ auxiliares

    /** Valor bruto de /Chave num dicionário (ref, <<...>> aninhado, [...] ou escalar). */
    private function dictValue(string $dict, string $key): ?string
    {
        if (preg_match('/\/' . preg_quote($key, '/') . '(?![A-Za-z0-9])\s*/', $dict, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $pos = $m[0][1] + strlen($m[0][0]);

        if (substr($dict, $pos, 2) === '<<') {
            $depth = 0;
            for ($j = $pos, $n = strlen($dict); $j < $n - 1; $j++) {
                $pair = substr($dict, $j, 2);
                if ($pair === '<<') {
                    $depth++;
                    $j++;
                } elseif ($pair === '>>') {
                    if (--$depth === 0) {
                        return substr($dict, $pos, $j + 2 - $pos);
                    }
                    $j++;
                }
            }

            return substr($dict, $pos);
        }

        if (($dict[$pos] ?? '') === '[') {
            $end = strpos($dict, ']', $pos);

            return substr($dict, $pos, ($end === false ? strlen($dict) : $end + 1) - $pos);
        }

        return preg_match('/(\d+\s+\d+\s+R|\S+)/A', $dict, $v, 0, $pos) === 1 ? $v[1] : null;
    }

    private function infoTitle(string $raw): string
    {
        if (preg_match('/\/Info\s+(\d+)\s+\d+\s+R/', $raw, $m) !== 1) {
            return '';
        }

        $info = $this->objects[(int) $m[1]]['dict'] ?? '';
        $value = $this->dictValue($info, 'Title');

        if ($value === null) {
            return '';
        }

        if (str_starts_with($value, '(')) {
            $pos = strpos($info, $value);
            [$bytes] = $this->literal($info, $pos !== false ? $pos : 0);
        } elseif (str_starts_with($value, '<')) {
            $bytes = (string) @hex2bin(preg_replace('/[^0-9A-Fa-f]/', '', $value) ?? '');
        } else {
            return '';
        }

        $title = str_starts_with($bytes, "\xFE\xFF")
            ? (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE')
            : (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        $title = trim(preg_replace('/^Microsoft (Word|PowerPoint) - /i', '', $title) ?? $title);

        return mb_substr(preg_replace('/\s+/u', ' ', $title) ?? $title, 0, 255);
    }

    private function cleanup(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $lines = array_map(static fn ($l) => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $l) ?? $l), explode("\n", $text));
        $text = implode("\n", $lines);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }
}
