<?php
/**
 * Simple PDF generator for QI documents (invoices, quotes, credit notes).
 * Produces a valid PDF 1.4 file from an array of text lines, without
 * requiring any external libraries.
 */

function qi_generate_simple_pdf(array $lines, string $outputPath): void
{
    $fontSize   = 10;
    $fontSizeH  = 14;
    $leading    = 14;     // line spacing in points
    $marginX    = 50;
    $marginTop  = 750;
    $pageWidth  = 595.28; // A4
    $pageHeight = 841.89; // A4

    // Build pages – each page holds a set of content lines
    $pages      = [];
    $pageLines  = [];
    $y          = $marginTop;
    $linesPerPage = (int)(($marginTop - 50) / $leading);

    foreach ($lines as $idx => $line) {
        $pageLines[] = $line;
        if (count($pageLines) >= $linesPerPage) {
            $pages[] = $pageLines;
            $pageLines = [];
        }
    }
    if ($pageLines) {
        $pages[] = $pageLines;
    }
    if (!$pages) {
        $pages[] = ['(empty document)'];
    }

    // PDF object collection
    $objects  = [];
    $objCount = 0;

    // Helper to add an object
    $addObj = function (string $content) use (&$objects, &$objCount): int {
        $objCount++;
        $objects[$objCount] = $content;
        return $objCount;
    };

    // 1 – Catalog (placeholder, filled later)
    $catalogId = $addObj('');
    // 2 – Pages node (placeholder)
    $pagesId   = $addObj('');

    // Escape a PDF text string
    $esc = function (string $s): string {
        $s = str_replace('\\', '\\\\', $s);
        $s = str_replace('(', '\\(', $s);
        $s = str_replace(')', '\\)', $s);
        return $s;
    };

    // Build each page
    $pageObjIds = [];
    foreach ($pages as $pi => $pLines) {
        // Stream content
        $stream = "BT\n";
        $y = $marginTop;
        foreach ($pLines as $li => $line) {
            $isHeader = ($pi === 0 && $li === 0);
            $fs = $isHeader ? $fontSizeH : $fontSize;
            $stream .= "/F1 {$fs} Tf\n";
            $stream .= "{$marginX} {$y} Td\n";
            $stream .= "(" . $esc($line) . ") Tj\n";
            $y -= $leading;
            // Reset text matrix for next absolute position
            $stream .= "ET\nBT\n";
        }
        $stream .= "ET\n";

        $streamLen = strlen($stream);
        $contentId = $addObj(
            "<< /Length {$streamLen} >>\nstream\n{$stream}endstream"
        );

        $fontId = $addObj(
            "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"
        );

        $pageId = $addObj(
            "<< /Type /Page /Parent {$pagesId} 0 R " .
            "/MediaBox [0 0 {$pageWidth} {$pageHeight}] " .
            "/Contents {$contentId} 0 R " .
            "/Resources << /Font << /F1 {$fontId} 0 R >> >> >>"
        );
        $pageObjIds[] = $pageId;
    }

    // Fill in catalog and pages
    $objects[$catalogId] = "<< /Type /Catalog /Pages {$pagesId} 0 R >>";
    $kidsStr = implode(' 0 R ', $pageObjIds) . ' 0 R';
    $pageCount = count($pageObjIds);
    $objects[$pagesId] = "<< /Type /Pages /Kids [{$kidsStr}] /Count {$pageCount} >>";

    // Serialize PDF
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objects as $num => $body) {
        $offsets[$num] = strlen($pdf);
        $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
    }

    // Cross-reference table
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . ($objCount + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $objCount; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }

    $pdf .= "trailer\n<< /Size " . ($objCount + 1) . " /Root {$catalogId} 0 R >>\n";
    $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";

    file_put_contents($outputPath, $pdf);
}
