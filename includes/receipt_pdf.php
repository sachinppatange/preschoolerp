<?php
/**
 * Minimal single-page fee receipt PDF (no third-party library).
 */
declare(strict_types=1);

function receipt_pdf_latin(string $s): string
{
    $s = str_replace(["\r", "\n"], ' ', $s);
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        if ($t !== false) {
            $s = $t;
        }
    }
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
}

/**
 * @param array<string,string> $lines  label => value
 */
function receipt_pdf_output(string $filename, string $title, array $lines): void
{
    $y = 760;
    $stream = "BT\n/F1 16 Tf\n50 {$y} Td\n(" . receipt_pdf_latin($title) . ") Tj\nET\n";
    $y -= 28;
    $stream .= "BT\n/F1 11 Tf\n";
    $first = true;
    foreach ($lines as $label => $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $text = receipt_pdf_latin(trim($label === '' ? $value : ($label . ': ' . $value)));
        if ($text === '') {
            continue;
        }
        if ($first) {
            $stream .= "50 {$y} Td\n({$text}) Tj\n";
            $first = false;
        } else {
            $stream .= "0 -18 Td\n({$text}) Tj\n";
        }
    }
    $stream .= "ET\n";

    $objects = [];
    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>";
    $objects[] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $body) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n{$body}\nendobj\n";
    }
    $xref = strlen($pdf);
    $count = count($objects) + 1;
    $pdf .= "xref\n0 {$count}\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i < $count; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer << /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'receipt.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0');
    echo $pdf;
    exit;
}
