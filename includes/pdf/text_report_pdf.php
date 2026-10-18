<?php
/**
 * Plain-text report PDF used by the /export/pdf/* finance exports.
 *
 * Renders pre-formatted, fixed-width text lines in Courier on A4 portrait
 * pages and returns the PDF bytes, starting a new page whenever one is full.
 *
 * Every line is placed with an absolute text matrix (`1 0 0 1 x y Tm`). The
 * per-file generators this replaces wrote `36 <y> Td` for each line inside a
 * single text object; `Td` moves relative to the start of the previous line,
 * so the offsets accumulated and every row after the first landed off the page.
 *
 * The font starts at 12pt on 14pt leading (the old layout) and shrinks just
 * enough for the widest line to fit between the side margins: the aging and
 * payroll tables are ~100 columns wide, which at 12pt Courier runs past the
 * right edge of the page.
 */

const FW_TEXT_PDF_PAGE_W   = 595;
const FW_TEXT_PDF_PAGE_H   = 842;
const FW_TEXT_PDF_MARGIN_X = 36;     // left and right
const FW_TEXT_PDF_TOP_Y    = 812;    // baseline of a page's first line
const FW_TEXT_PDF_BOTTOM_Y = 36;     // lowest baseline allowed on a page
const FW_TEXT_PDF_MAX_SIZE = 12.0;
const FW_TEXT_PDF_MIN_SIZE = 6.0;    // past this a too-wide line is clipped instead
const FW_TEXT_PDF_LEADING  = 14 / 12; // line spacing as a multiple of the font size
const FW_TEXT_PDF_CHAR_W   = 0.6;    // Courier advance width: 600/1000 em

function fw_text_pdf_escape(string $str): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $str);
}

/**
 * Largest font size (pt, one decimal) at which every line fits the printable
 * width, within FW_TEXT_PDF_MIN_SIZE..FW_TEXT_PDF_MAX_SIZE.
 */
function fw_text_pdf_font_size(array $lines): float
{
    $maxChars = 1;
    foreach ($lines as $line) {
        // The string bytes go into the PDF as-is and the font is single-byte,
        // so each byte is one glyph.
        $maxChars = max($maxChars, strlen((string)$line));
    }
    $fit = (FW_TEXT_PDF_PAGE_W - 2 * FW_TEXT_PDF_MARGIN_X) / ($maxChars * FW_TEXT_PDF_CHAR_W);
    return max(FW_TEXT_PDF_MIN_SIZE, min(FW_TEXT_PDF_MAX_SIZE, floor($fit * 10) / 10));
}

/** Build the PDF document for the given lines (top to bottom, in order). */
function fw_text_report_pdf(array $lines): string
{
    $fontSize = fw_text_pdf_font_size($lines);
    $leading  = $fontSize * FW_TEXT_PDF_LEADING;
    $perPage  = (int)floor((FW_TEXT_PDF_TOP_Y - FW_TEXT_PDF_BOTTOM_Y) / $leading) + 1;
    $pages    = array_chunk(array_values($lines), $perPage) ?: [[]];

    // 1 catalog, 2 page tree, 3 font, then a page + content stream per page.
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '', // filled in once the pages are numbered
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>',
    ];
    $kids = [];
    foreach ($pages as $pageLines) {
        $content = sprintf("BT\n/F1 %.1f Tf\n", $fontSize);
        foreach ($pageLines as $i => $line) {
            $content .= sprintf(
                "1 0 0 1 %d %.2f Tm (%s) Tj\n",
                FW_TEXT_PDF_MARGIN_X,
                FW_TEXT_PDF_TOP_Y - $i * $leading,
                fw_text_pdf_escape((string)$line)
            );
        }
        $content .= 'ET';

        $pageId    = count($objects) + 1;
        $contentId = $pageId + 1;
        $objects[$pageId] = sprintf(
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Contents %d 0 R /Resources << /Font << /F1 3 0 R >> >> >>',
            FW_TEXT_PDF_PAGE_W,
            FW_TEXT_PDF_PAGE_H,
            $contentId
        );
        $objects[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        $kids[] = $pageId . ' 0 R';
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $num => $body) {
        $offsets[$num] = strlen($pdf);
        $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $off) {
        $pdf .= sprintf("%010d 00000 n \n", $off);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";
    return $pdf;
}
