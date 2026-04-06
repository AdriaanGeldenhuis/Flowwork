<?php
/**
 * Styled PDF generator for QI documents (invoices, quotes, credit notes).
 * Produces a professional A4 PDF with header, table, totals, sections, and
 * page numbers — all without external libraries (pure PDF 1.4).
 *
 * Usage:
 *   qi_generate_styled_pdf($doc, $lines, $outputPath);
 *
 *   $doc = associative array with document + company + customer fields
 *   $lines = array of line items (item_description, quantity, unit_price, line_total)
 */

function qi_generate_styled_pdf(array $doc, array $lines, string $outputPath): void
{
    $pdf = new QiStyledPdfWriter($doc, $lines);
    file_put_contents($outputPath, $pdf->render());
}

class QiStyledPdfWriter
{
    // A4 dimensions in points (1pt = 1/72 inch)
    private float $pageW = 595.28;
    private float $pageH = 841.89;
    private float $marginL = 50;
    private float $marginR = 50;
    private float $marginT = 60;
    private float $marginB = 50;

    private array $doc;
    private array $lines;
    private array $objects = [];
    private int $objCount = 0;
    private array $pages = [];

    // Current page state
    private string $stream = '';
    private float $y;
    private int $pageNum = 0;
    private int $totalPages = 0;

    // Font IDs (set per page)
    private int $fontRegId;
    private int $fontBoldId;

    // Colours
    private string $accentR;
    private string $accentG;
    private string $accentB;
    private string $headingR;
    private string $headingG;
    private string $headingB;
    private string $textR = '0.216';
    private string $textG = '0.255';
    private string $textB = '0.318';
    private string $thTextR = '1';
    private string $thTextG = '1';
    private string $thTextB = '1';

    public function __construct(array $doc, array $lines)
    {
        $this->doc = $doc;
        $this->lines = $lines;

        // Parse branding colours from company settings
        $primary = $doc['primary_color'] ?? '#fbbf24';
        [$this->accentR, $this->accentG, $this->accentB] = $this->hexToRgb($primary);

        $heading = $doc['qi_heading_color'] ?: $primary;
        [$this->headingR, $this->headingG, $this->headingB] = $this->hexToRgb($heading);

        if (!empty($doc['qi_text_color'])) {
            [$this->textR, $this->textG, $this->textB] = $this->hexToRgb($doc['qi_text_color']);
        }

        if (!empty($doc['qi_table_header_text'])) {
            [$this->thTextR, $this->thTextG, $this->thTextB] = $this->hexToRgb($doc['qi_table_header_text']);
        }
    }

    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) $hex = 'fbbf24'; // fallback
        return [
            sprintf('%.3f', hexdec(substr($hex, 0, 2)) / 255),
            sprintf('%.3f', hexdec(substr($hex, 2, 2)) / 255),
            sprintf('%.3f', hexdec(substr($hex, 4, 2)) / 255),
        ];
    }

    public function render(): string
    {
        // --- First pass: build all page content ---
        $this->newPage();
        $this->drawHeader();
        $this->drawDetails();
        $this->drawLineItems();
        $this->drawTotals();
        $this->drawSections();
        $this->drawFooterText();
        $this->finishPage();

        $this->totalPages = $this->pageNum;

        // --- Assemble PDF ---
        return $this->assemblePdf();
    }

    // ===== PAGE MANAGEMENT =====

    private function newPage(): void
    {
        if ($this->pageNum > 0) {
            $this->finishPage();
        }
        $this->pageNum++;
        $this->y = $this->pageH - $this->marginT;
        $this->stream = '';
    }

    private function finishPage(): void
    {
        // Draw page number at bottom centre
        $numText = 'Page ' . $this->pageNum;
        $this->stream .= "BT\n/F1 8 Tf\n0.6 0.6 0.6 rg\n";
        $this->stream .= sprintf("%.2f %.2f Td\n", $this->pageW / 2 - 15, 25);
        $this->stream .= '(' . $this->esc($numText) . ") Tj\nET\n";

        $this->pages[] = $this->stream;
    }

    private function contentW(): float
    {
        return $this->pageW - $this->marginL - $this->marginR;
    }

    private function checkSpace(float $needed): void
    {
        if ($this->y - $needed < $this->marginB) {
            $this->newPage();
        }
    }

    // ===== DRAWING PRIMITIVES =====

    private function text(string $font, float $size, float $x, float $y, string $text, string $r = '0.1', string $g = '0.1', string $b = '0.1'): void
    {
        $this->stream .= "BT\n/{$font} {$size} Tf\n{$r} {$g} {$b} rg\n";
        $this->stream .= sprintf("%.2f %.2f Td\n", $x, $y);
        $this->stream .= '(' . $this->esc($text) . ") Tj\nET\n";
    }

    private function textRight(string $font, float $size, float $x, float $y, string $text, string $r = '0.1', string $g = '0.1', string $b = '0.1'): void
    {
        $w = $this->approxWidth($text, $size);
        $this->text($font, $size, $x - $w, $y, $text, $r, $g, $b);
    }

    private function rect(float $x, float $y, float $w, float $h, string $r, string $g, string $b): void
    {
        $this->stream .= "{$r} {$g} {$b} rg\n";
        $this->stream .= sprintf("%.2f %.2f %.2f %.2f re f\n", $x, $y, $w, $h);
    }

    private function line(float $x1, float $y1, float $x2, float $y2, float $width, string $r, string $g, string $b): void
    {
        $this->stream .= sprintf("%.3f w\n%s %s %s RG\n%.2f %.2f m %.2f %.2f l S\n", $width, $r, $g, $b, $x1, $y1, $x2, $y2);
    }

    private function approxWidth(string $text, float $fontSize): float
    {
        // Approximate width using average char width for Helvetica (~0.52 of font size)
        return strlen($text) * $fontSize * 0.52;
    }

    // ===== DOCUMENT SECTIONS =====

    private function drawHeader(): void
    {
        $x = $this->marginL;
        $rightX = $this->pageW - $this->marginR;

        // Document type (left) — uses heading colour
        $docType = strtoupper($this->doc['_doc_type'] ?? 'INVOICE');
        $this->text('F2', 18, $x, $this->y, $docType, $this->headingR, $this->headingG, $this->headingB);

        // Company name
        $companyName = $this->doc['company_name'] ?? '';
        $this->text('F2', 11, $x, $this->y - 24, $companyName, '0.15', '0.15', '0.15');

        // Company info lines (left side — address, phone, email only)
        $leftY = $this->y - 40;
        $infoLines = [];
        $addr1 = trim(($this->doc['company_address1'] ?? '') . ' ' . ($this->doc['company_address2'] ?? ''));
        if ($addr1) $infoLines[] = $addr1;
        $cityLine = trim(($this->doc['company_city'] ?? '') . ', ' . ($this->doc['company_region'] ?? '') . ' ' . ($this->doc['company_postal'] ?? ''));
        if ($cityLine && $cityLine !== ', ') $infoLines[] = $cityLine;
        if (!empty($this->doc['company_phone'])) $infoLines[] = 'Tel: ' . $this->doc['company_phone'];
        if (!empty($this->doc['company_email'])) $infoLines[] = $this->doc['company_email'];
        if (!empty($this->doc['website'])) $infoLines[] = $this->doc['website'];
        foreach ($infoLines as $line) {
            $this->text('F1', 8, $x, $leftY, $line, '0.35', '0.35', '0.35');
            $leftY -= 11;
        }

        // Document number + dates (right side)
        $docNumber = $this->doc['_doc_title'] ?? '';
        $this->textRight('F2', 14, $rightX, $this->y, $docNumber, $this->textR, $this->textG, $this->textB);

        $rightY = $this->y - 22;
        $dates = $this->doc['_dates'] ?? [];
        foreach ($dates as $label => $value) {
            $this->textRight('F1', 9, $rightX, $rightY, $label . ': ' . $value, '0.35', '0.35', '0.35');
            $rightY -= 13;
        }

        // Registration numbers (right side, below dates)
        $rightY -= 5;
        if (!empty($this->doc['vat_number'])) {
            $this->textRight('F1', 8, $rightX, $rightY, 'VAT: ' . $this->doc['vat_number'], '0.45', '0.45', '0.45');
            $rightY -= 11;
        }
        if (!empty($this->doc['tax_number'])) {
            $this->textRight('F1', 8, $rightX, $rightY, 'Tax: ' . $this->doc['tax_number'], '0.45', '0.45', '0.45');
            $rightY -= 11;
        }
        if (!empty($this->doc['reg_number'])) {
            $this->textRight('F1', 8, $rightX, $rightY, 'Reg: ' . $this->doc['reg_number'], '0.45', '0.45', '0.45');
            $rightY -= 11;
        }

        // Bill To (right side, below status/dates)
        $rightY -= 8;
        $this->textRight('F2', 8, $rightX, $rightY, 'BILL TO', $this->headingR, $this->headingG, $this->headingB);
        $rightY -= 13;
        $this->textRight('F2', 9, $rightX, $rightY, $this->doc['customer_name'] ?? 'Customer', '0.1', '0.1', '0.1');
        $rightY -= 12;
        $custAddr = trim(($this->doc['customer_address1'] ?? '') . ' ' . ($this->doc['customer_address2'] ?? ''));
        $custCity = trim(($this->doc['customer_city'] ?? '') . ', ' . ($this->doc['customer_region'] ?? '') . ' ' . ($this->doc['customer_postal'] ?? ''));
        if ($custAddr) {
            $this->textRight('F1', 8, $rightX, $rightY, $custAddr, '0.35', '0.35', '0.35');
            $rightY -= 11;
        }
        if ($custCity && $custCity !== ', ') {
            $this->textRight('F1', 8, $rightX, $rightY, $custCity, '0.35', '0.35', '0.35');
            $rightY -= 11;
        }
        if (!empty($this->doc['customer_phone'])) {
            $this->textRight('F1', 8, $rightX, $rightY, 'Phone: ' . $this->doc['customer_phone'], '0.35', '0.35', '0.35');
            $rightY -= 11;
        }
        if (!empty($this->doc['customer_email'])) {
            $this->textRight('F1', 8, $rightX, $rightY, 'Email: ' . $this->doc['customer_email'], '0.35', '0.35', '0.35');
            $rightY -= 11;
        }
        if (!empty($this->doc['customer_vat'])) {
            $this->textRight('F1', 8, $rightX, $rightY, 'VAT: ' . $this->doc['customer_vat'], '0.35', '0.35', '0.35');
            $rightY -= 11;
        }
        if (!empty($this->doc['customer_reg'])) {
            $this->textRight('F1', 8, $rightX, $rightY, 'Reg: ' . $this->doc['customer_reg'], '0.35', '0.35', '0.35');
            $rightY -= 11;
        }

        // Accent line — position below whichever column is taller
        $headerBottom = min($leftY, $rightY) - 8;
        $this->line($x, $headerBottom, $rightX, $headerBottom, 3, $this->accentR, $this->accentG, $this->accentB);

        $this->y = $headerBottom - 18;
    }

    private function drawDetails(): void
    {
        // Bill To is now in the header (right side, under status).
        // Only draw Project box if applicable.
        if (empty($this->doc['project_name'])) {
            return;
        }

        $x = $this->marginL;
        $boxW = $this->contentW() / 2;
        $boxH = 40;

        $this->checkSpace($boxH + 18);

        $this->rect($x, $this->y - $boxH, $boxW, $boxH, '0.96', '0.96', '0.97');
        $this->rect($x, $this->y - $boxH, 3, $boxH, '0.6', '0.6', '0.65');
        $this->text('F2', 8, $x + 12, $this->y - 14, 'PROJECT', $this->headingR, $this->headingG, $this->headingB);
        $this->text('F1', 10, $x + 12, $this->y - 28, $this->doc['project_name'], '0.1', '0.1', '0.1');

        $this->y -= ($boxH + 18);
    }

    private function drawLineItems(): void
    {
        $x = $this->marginL;
        $w = $this->contentW();
        $rowH = 22;
        $headerH = 24;

        // Column positions (relative to $x)
        $colDesc = 0;
        $colQty = $w * 0.55;
        $colPrice = $w * 0.70;
        $colTotal = $w * 0.85;

        // Table header
        $this->checkSpace($headerH + $rowH);
        $this->drawTableHeader($x, $w, $headerH, $colDesc, $colQty, $colPrice, $colTotal);
        $this->y -= $headerH;

        // Rows
        $even = false;
        foreach ($this->lines as $li) {
            $desc = $li['item_description'] ?? '';
            // Estimate row height: wrap long descriptions
            $descLines = $this->wrapText($desc, 9, $colQty - 10);
            $lineRowH = max($rowH, count($descLines) * 13 + 8);

            $this->checkSpace($lineRowH);

            // Alternating row background
            if ($even) {
                $this->rect($x, $this->y - $lineRowH, $w, $lineRowH, '0.98', '0.98', '0.99');
            }
            // Bottom border
            $this->line($x, $this->y - $lineRowH, $x + $w, $this->y - $lineRowH, 0.3, '0.9', '0.9', '0.9');

            // Description (potentially multi-line)
            $textY = $this->y - 14;
            foreach ($descLines as $dl) {
                $this->text('F1', 9, $x + 8, $textY, $dl, '0.1', '0.1', '0.1');
                $textY -= 13;
            }

            // Qty, Price, Total
            $midY = $this->y - 14;
            $this->textRight('F1', 9, $x + $colPrice - 8, $midY, number_format((float)($li['quantity'] ?? 0), 2), '0.1', '0.1', '0.1');
            $this->textRight('F1', 9, $x + $colTotal - 8, $midY, $this->fmt($li['unit_price'] ?? 0), '0.1', '0.1', '0.1');
            $this->textRight('F1', 9, $x + $w - 8, $midY, $this->fmt($li['line_total'] ?? 0), '0.1', '0.1', '0.1');

            $this->y -= $lineRowH;
            $even = !$even;
        }

        $this->y -= 10;
    }

    private function drawTableHeader(float $x, float $w, float $h, float $colDesc, float $colQty, float $colPrice, float $colTotal): void
    {
        // Accent header bar
        $this->rect($x, $this->y - $h, $w, $h, $this->accentR, $this->accentG, $this->accentB);

        $midY = $this->y - ($h * 0.6);
        $this->text('F2', 8, $x + $colDesc + 8, $midY, 'DESCRIPTION', $this->thTextR, $this->thTextG, $this->thTextB);
        $this->textRight('F2', 8, $x + $colPrice - 8, $midY, 'QTY', $this->thTextR, $this->thTextG, $this->thTextB);
        $this->textRight('F2', 8, $x + $colTotal - 8, $midY, 'UNIT PRICE', $this->thTextR, $this->thTextG, $this->thTextB);
        $this->textRight('F2', 8, $x + $w - 8, $midY, 'LINE TOTAL', $this->thTextR, $this->thTextG, $this->thTextB);
    }

    private function drawTotals(): void
    {
        $boxW = 220;
        $x = $this->pageW - $this->marginR - $boxW;
        $rowH = 18;

        $totalRows = [];
        $totalRows[] = ['Subtotal:', $this->fmt($this->doc['subtotal'] ?? 0)];
        if ((float)($this->doc['discount'] ?? 0) > 0) {
            $totalRows[] = ['Discount:', $this->fmt($this->doc['discount'])];
        }
        $totalRows[] = ['VAT (15%):', $this->fmt($this->doc['tax'] ?? 0)];

        $needed = (count($totalRows) + 1) * $rowH + 10;
        $this->checkSpace($needed);

        foreach ($totalRows as $row) {
            $this->line($x, $this->y, $x + $boxW, $this->y, 0.3, '0.9', '0.9', '0.9');
            $this->text('F1', 10, $x + 8, $this->y - 13, $row[0], '0.3', '0.3', '0.3');
            $this->textRight('F1', 10, $x + $boxW - 8, $this->y - 13, $row[1], '0.1', '0.1', '0.1');
            $this->y -= $rowH;
        }

        // Grand total row — accent line + accent text
        $this->line($x, $this->y, $x + $boxW, $this->y, 2, $this->accentR, $this->accentG, $this->accentB);
        $this->y -= 4;
        $grandH = 22;
        $this->text('F2', 14, $x + 8, $this->y - 16, 'TOTAL:', $this->accentR, $this->accentG, $this->accentB);
        $this->textRight('F2', 14, $x + $boxW - 8, $this->y - 16, $this->fmt($this->doc['total'] ?? 0), $this->accentR, $this->accentG, $this->accentB);
        $this->y -= ($grandH + 5);

        // Balance due (invoices)
        if (isset($this->doc['balance_due']) && (float)($this->doc['balance_due']) < (float)($this->doc['total'] ?? 0)) {
            $this->text('F1', 10, $x + 8, $this->y - 13, 'Balance Due:', '0.3', '0.3', '0.3');
            $this->textRight('F2', 10, $x + $boxW - 8, $this->y - 13, $this->fmt($this->doc['balance_due']), '0.1', '0.1', '0.1');
            $this->y -= $rowH;
        }

        $this->y -= 12;
    }

    private function drawSections(): void
    {
        // Payment Details
        if (!empty($this->doc['bank_name']) || !empty($this->doc['bank_account_number'])) {
            $this->drawSection('Payment Details', function () {
                $lines = [];
                if (!empty($this->doc['bank_name'])) $lines[] = 'Bank: ' . $this->doc['bank_name'];
                if (!empty($this->doc['bank_account_number'])) $lines[] = 'Account No: ' . $this->doc['bank_account_number'];
                if (!empty($this->doc['bank_branch_code'])) $lines[] = 'Branch Code: ' . $this->doc['bank_branch_code'];
                return $lines;
            });
        }

        // Terms & Conditions
        if (!empty($this->doc['terms'])) {
            $this->drawSection('Terms & Conditions', function () {
                return $this->wrapText($this->doc['terms'], 9, $this->contentW() - 16);
            });
        }

        // Notes
        if (!empty($this->doc['notes'])) {
            $this->drawSection('Notes', function () {
                return $this->wrapText($this->doc['notes'], 9, $this->contentW() - 16);
            });
        }
    }

    private function drawSection(string $title, callable $contentFn): void
    {
        $contentLines = $contentFn();
        $needed = 30 + count($contentLines) * 13;
        $this->checkSpace(min($needed, 100)); // at least fit title + some content

        $x = $this->marginL;

        // Title
        $this->text('F2', 10, $x, $this->y, $title, $this->headingR, $this->headingG, $this->headingB);
        $this->y -= 4;
        $this->line($x, $this->y, $x + $this->contentW(), $this->y, 0.5, '0.85', '0.85', '0.85');
        $this->y -= 14;

        foreach ($contentLines as $cl) {
            $this->checkSpace(15);
            $this->text('F1', 9, $x + 4, $this->y, $cl, '0.3', '0.3', '0.3');
            $this->y -= 13;
        }

        $this->y -= 10;
    }

    private function drawFooterText(): void
    {
        if (empty($this->doc['invoice_footer_text'])) return;

        $lines = $this->wrapText($this->doc['invoice_footer_text'], 8, $this->contentW());
        $this->checkSpace(20 + count($lines) * 11);

        $cx = $this->pageW / 2;
        foreach ($lines as $fl) {
            $w = $this->approxWidth($fl, 8);
            $this->text('F1', 8, $cx - $w / 2, $this->y, $fl, '0.55', '0.55', '0.55');
            $this->y -= 11;
        }
    }

    // ===== TEXT WRAPPING =====

    private function wrapText(string $text, float $fontSize, float $maxWidth): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $paragraphs = explode("\n", $text);
        $result = [];

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') {
                $result[] = '';
                continue;
            }
            $words = explode(' ', $para);
            $line = '';
            foreach ($words as $word) {
                $test = $line === '' ? $word : $line . ' ' . $word;
                if ($this->approxWidth($test, $fontSize) > $maxWidth && $line !== '') {
                    $result[] = $line;
                    $line = $word;
                } else {
                    $line = $test;
                }
            }
            if ($line !== '') {
                $result[] = $line;
            }
        }

        return $result;
    }

    // ===== PDF ASSEMBLY =====

    private function assemblePdf(): string
    {
        $objects = [];
        $objCount = 0;

        $addObj = function (string $content) use (&$objects, &$objCount): int {
            $objCount++;
            $objects[$objCount] = $content;
            return $objCount;
        };

        // Catalog + Pages placeholders
        $catalogId = $addObj('');
        $pagesId = $addObj('');

        // Fonts (Helvetica + Helvetica-Bold)
        $fontRegId = $addObj('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        $fontBoldId = $addObj('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>');

        $pageObjIds = [];
        foreach ($this->pages as $streamContent) {
            $streamLen = strlen($streamContent);
            $contentId = $addObj("<< /Length {$streamLen} >>\nstream\n{$streamContent}endstream");

            $pageId = $addObj(
                "<< /Type /Page /Parent {$pagesId} 0 R " .
                "/MediaBox [0 0 {$this->pageW} {$this->pageH}] " .
                "/Contents {$contentId} 0 R " .
                "/Resources << /Font << /F1 {$fontRegId} 0 R /F2 {$fontBoldId} 0 R >> >> >>"
            );
            $pageObjIds[] = $pageId;
        }

        // Fill catalog and pages
        $objects[$catalogId] = "<< /Type /Catalog /Pages {$pagesId} 0 R >>";
        $kidsStr = implode(' 0 R ', $pageObjIds) . ' 0 R';
        $pageCount = count($pageObjIds);
        $objects[$pagesId] = "<< /Type /Pages /Kids [{$kidsStr}] /Count {$pageCount} >>";

        // Serialize
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
        }

        // Cross-reference
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . ($objCount + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $objCount; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size " . ($objCount + 1) . " /Root {$catalogId} 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }

    // ===== HELPERS =====

    private function esc(string $s): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    private function fmt($amount): string
    {
        return 'R ' . number_format((float)($amount ?? 0), 2);
    }
}
