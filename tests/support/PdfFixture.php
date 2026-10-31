<?php

declare(strict_types=1);

/**
 * Gera PDFs mínimos e válidos para os testes (sem bibliotecas externas).
 */
final class PdfFixture
{
    /** @param array<int, string> $objs */
    public static function build(array $objs, int $root = 1, ?int $info = null): string
    {
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $off = [];

        foreach ($objs as $n => $body) {
            $off[$n] = strlen($out);
            $out .= "$n 0 obj\n$body\nendobj\n";
        }

        $xref = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";

        for ($i = 1; $i <= $max; $i++) {
            $out .= sprintf("%010d 00000 n \n", $off[$i] ?? 0);
        }

        return $out . "trailer\n<< /Size " . ($max + 1) . " /Root $root 0 R" . ($info ? " /Info $info 0 R" : '') . " >>\nstartxref\n$xref\n%%EOF\n";
    }

    public static function stream(string $data, bool $flate = false): string
    {
        $data = $flate ? (string) gzcompress($data) : $data;

        return '<< /Length ' . strlen($data) . ($flate ? ' /Filter /FlateDecode' : '') . " >>\nstream\n$data\nendstream";
    }

    /** PDF textual (Helvetica/WinAnsi) com uma página por item de $pages; texto em Windows-1252. */
    public static function text(array $pages, ?string $title = null): string
    {
        $count = count($pages);
        $objs = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'];
        $kids = [];
        $n = 4;

        foreach ($pages as $lines) {
            $ops = 'BT /F1 11 Tf 72 720 Td';

            foreach ((array) $lines as $line) {
                $ops .= ' (' . addcslashes((string) mb_convert_encoding($line, 'Windows-1252', 'UTF-8'), '()\\') . ') Tj 0 -14 Td';
            }

            $objs[$n] = '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 3 0 R >> >> /Contents ' . ($n + 1) . ' 0 R >>';
            $objs[$n + 1] = self::stream($ops . ' ET', true);
            $kids[] = "$n 0 R";
            $n += 2;
        }

        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $count . ' >>';
        $info = null;

        if ($title !== null) {
            $objs[$n] = '<< /Title (' . addcslashes((string) mb_convert_encoding($title, 'Windows-1252', 'UTF-8'), '()\\') . ') >>';
            $info = $n;
        }

        ksort($objs);

        return self::build($objs, 1, $info);
    }

    /** PDF "digitalizado": só uma imagem, sem camada de texto. */
    public static function scanned(): string
    {
        return self::build([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Resources << /XObject << /Im1 5 0 R >> >> /Contents 4 0 R >>',
            4 => self::stream('q 500 0 0 700 50 50 cm /Im1 Do Q', true),
            5 => "<< /Type /XObject /Subtype /Image /Width 2 /Height 2 /BitsPerComponent 8 /ColorSpace /DeviceGray /Filter /DCTDecode /Length 4 >>\nstream\n\xFF\xD8\xFF\xD9\nendstream",
        ]);
    }
}
