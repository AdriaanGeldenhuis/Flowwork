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

if (!class_exists('Branding')) {
    require_once __DIR__ . '/../../qi/lib/Branding.php';
}
if (!class_exists('Currencies')) {
    require_once __DIR__ . '/../../qi/lib/Currencies.php';
}

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
    // Optional extras passed via $doc['_milestones'] / $doc['_payments'] so the
    // downloaded/emailed PDF shows the same payment schedule and payment
    // history as the on-screen invoice.
    private array $milestones = [];
    private array $payments = [];
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

    // PDF base-14 font names, chosen from the company's qi_font_family.
    private string $fontReg = 'Helvetica';
    private string $fontBold = 'Helvetica-Bold';

    // Company display toggles (which detail rows to print). Default ON.
    private array $show;

    // Document currency — the writer used to hardcode "R". Now it mirrors the
    // on-screen/print surfaces: symbol from the doc's currency, plus the
    // ZAR-equivalent line for foreign documents.
    private string $curSymbol = 'R';
    private string $curCode = 'ZAR';
    private float $fxRate = 1.0;
    private bool $isForeign = false;

    // Company logo, decoded via GD into raw RGB (+ alpha soft-mask) ready to
    // embed as a PDF image XObject. Null when there is no logo or it can't
    // be read — the PDF then renders exactly as before.
    private ?array $logo = null;

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
        $this->milestones = is_array($doc['_milestones'] ?? null) ? $doc['_milestones'] : [];
        $this->payments   = is_array($doc['_payments'] ?? null) ? $doc['_payments'] : [];

        // Parse branding colours from company settings
        $primary = Branding::sanitizeColor($doc['primary_color'] ?? null, '#fbbf24');
        [$this->accentR, $this->accentG, $this->accentB] = $this->hexToRgb($primary);

        $heading = Branding::sanitizeColor(($doc['qi_heading_color'] ?? '') ?: $primary, $primary);
        [$this->headingR, $this->headingG, $this->headingB] = $this->hexToRgb($heading);

        if (!empty($doc['qi_text_color'])) {
            [$this->textR, $this->textG, $this->textB] = $this->hexToRgb(
                Branding::sanitizeColor($doc['qi_text_color'], '#374151')
            );
        }

        // Table-header text: honour an explicit colour that reads on the
        // primary, otherwise auto-pick black/white so it stays legible
        // (matches the on-screen / printed document).
        $theadText = Branding::sanitizeColor($doc['qi_table_header_text'] ?? null, '#ffffff');
        if (Branding::contrastRatio($theadText, $primary) < 3.0) {
            $theadText = Branding::idealInk($primary);
        }
        [$this->thTextR, $this->thTextG, $this->thTextB] = $this->hexToRgb($theadText);

        // Font: map qi_font_family to a PDF base-14 font (serif -> Times).
        $fontKey = (string)($doc['qi_font_family'] ?? 'system-ui');
        [$this->fontReg, $this->fontBold] = Branding::PDF_FONTS[$fontKey] ?? Branding::PDF_FONTS['system-ui'];

        // Document currency (matches invoice_view.php / generate_pdf.php).
        $this->curCode   = Currencies::isValid($doc['currency'] ?? null) ? strtoupper($doc['currency']) : Currencies::BASE;
        $this->curSymbol = Currencies::symbol($this->curCode);
        $this->fxRate    = (float)($doc['exchange_rate'] ?? 1) ?: 1.0;
        $this->isForeign = ($this->curCode !== Currencies::BASE);

        // Company logo (same image the on-screen invoice shows top-left).
        $this->loadLogo();

        // Display toggles (default ON when the column is null/missing).
        $tog = static fn($v) => $v === null ? true : (bool)(int)$v;
        $this->show = [
            'address' => $tog($doc['qi_show_company_address'] ?? null),
            'phone'   => $tog($doc['qi_show_company_phone']   ?? null),
            'email'   => $tog($doc['qi_show_company_email']   ?? null),
            'website' => $tog($doc['qi_show_company_website'] ?? null),
            'vat'     => $tog($doc['qi_show_vat_number']      ?? null),
            'tax'     => $tog($doc['qi_show_tax_number']      ?? null),
            'reg'     => $tog($doc['qi_show_reg_number']      ?? null),
            'payment' => $tog($doc['qi_show_payment_details'] ?? null),
        ];
    }

    /**
     * Load the company logo (doc['logo_url']) and decode it with GD into raw
     * RGB pixels plus an alpha soft-mask, ready for embedding as a PDF image.
     * Logos are stored as WebP (see qi/ajax/upload_logo.php) but any format
     * GD can read works. Failure is always non-fatal: no logo, same PDF as
     * before.
     */
    private function loadLogo(): void
    {
        try {
            $url = trim((string)($this->doc['logo_url'] ?? ''));
            if ($url === '' || !function_exists('imagecreatefromstring')) {
                return;
            }

            $bytes = false;
            if (preg_match('~^https?://~i', $url)) {
                $ctx = stream_context_create(['http' => ['timeout' => 5]]);
                $bytes = @file_get_contents($url, false, $ctx);
            } else {
                // Site-relative path (e.g. /uploads/{company}/logo/logo.webp)
                $root = realpath(__DIR__ . '/../..');
                $path = $root ? realpath($root . '/' . ltrim($url, '/')) : false;
                if ($path && strpos($path, $root) === 0 && is_file($path)) {
                    $bytes = @file_get_contents($path);
                }
            }
            if ($bytes === false || $bytes === '') {
                return;
            }

            // Check dimensions BEFORE decoding: a tiny compressed file can
            // expand to a huge bitmap (pixel flood), so reject anything far
            // beyond a sane logo size instead of letting GD allocate it.
            $info = @getimagesizefromstring($bytes);
            if ($info === false) {
                return;
            }
            $srcW = (int)$info[0];
            $srcH = (int)$info[1];
            if ($srcW < 1 || $srcH < 1 || $srcW > 4000 || $srcH > 4000 || ($srcW * $srcH) > 4000000) {
                return;
            }

            $img = @imagecreatefromstring($bytes);
            if (!$img) {
                return;
            }
            if (!imageistruecolor($img)) {
                imagepalettetotruecolor($img);
            }

            $w = imagesx($img);
            $h = imagesy($img);

            // Cap pixel size to keep the embedded image small (uploads are
            // already resized to <=800px, but logo_url may point elsewhere).
            $maxPx = 800;
            if ($w > $maxPx || $h > $maxPx) {
                $scale = min($maxPx / $w, $maxPx / $h);
                $nw = max(1, (int)round($w * $scale));
                $nh = max(1, (int)round($h * $scale));
                $dst = imagecreatetruecolor($nw, $nh);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($img);
                $img = $dst;
                $w = $nw;
                $h = $nh;
            }

            $rgb = '';
            $mask = '';
            $hasAlpha = false;
            for ($py = 0; $py < $h; $py++) {
                for ($px = 0; $px < $w; $px++) {
                    $c = imagecolorat($img, $px, $py);
                    $rgb .= chr(($c >> 16) & 0xFF) . chr(($c >> 8) & 0xFF) . chr($c & 0xFF);
                    $a = ($c >> 24) & 0x7F; // GD alpha: 0 = opaque, 127 = transparent
                    $ab = 255 - (int)round($a * 255 / 127);
                    if ($ab < 255) {
                        $hasAlpha = true;
                    }
                    $mask .= chr($ab);
                }
            }
            imagedestroy($img);

            $data = gzcompress($rgb, 6);
            if ($data === false) {
                return;
            }
            $smask = null;
            if ($hasAlpha) {
                $smask = gzcompress($mask, 6);
                if ($smask === false) {
                    return;
                }
            }

            $this->logo = [
                'w' => $w,
                'h' => $h,
                'data' => $data,
                'smask' => $smask,
            ];
        } catch (\Throwable $e) {
            // A broken logo must never block invoice/quote PDF generation.
            $this->logo = null;
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
        $this->drawMilestones();
        $this->drawPayments();
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

    /** Draw the logo XObject; (x, y) is the lower-left corner. */
    private function drawImage(float $x, float $y, float $w, float $h): void
    {
        $this->stream .= sprintf("q\n%.2f 0 0 %.2f %.2f %.2f cm\n/Im1 Do\nQ\n", $w, $h, $x, $y);
    }

    private function approxWidth(string $text, float $fontSize): float
    {
        // Approximate width using average char width for Helvetica (~0.52 of
        // font size). Measure the WinAnsi-transcoded string so multibyte UTF-8
        // characters count as one glyph each (not 2-3 bytes) — otherwise
        // right-aligned accented text lands short of the margin.
        return strlen($this->win($text)) * $fontSize * 0.52;
    }

    /**
     * Transcode UTF-8 to Windows-1252 (the WinAnsiEncoding the base-14 fonts
     * declare) so accented and common punctuation glyphs render correctly
     * instead of as mojibake. Characters with no CP1252 equivalent are
     * transliterated (e.g. № -> "No", ✓ dropped); never fatal.
     */
    private function win(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        $conv = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        if ($conv === false) {
            $conv = @iconv('UTF-8', 'Windows-1252//IGNORE', $s);
        }
        return $conv === false ? $s : $conv;
    }

    // ===== DOCUMENT SECTIONS =====

    private function drawHeader(): void
    {
        $x = $this->marginL;
        $rightX = $this->pageW - $this->marginR;

        // Left column: logo (when set) above the company block — same as the
        // on-screen invoice, which shows the logo image top-left.
        $leftY = $this->y;
        if ($this->logo) {
            // Natural size at 0.75pt/px (CSS px -> pt), fitted into the
            // company's qi_logo_size box (clamped like Branding::resolve)
            // and never upscaled — mirrors the on-screen max-width/
            // max-height + object-fit: contain sizing.
            $sizePx = max(80, min(400, (int)($this->doc['qi_logo_size'] ?? 200)));
            $boxW = $sizePx * 0.75;
            $boxH = 120; // on-screen max-height: 160px
            $natW = $this->logo['w'] * 0.75;
            $natH = $this->logo['h'] * 0.75;
            $scale = min(1, $boxW / $natW, $boxH / $natH);
            $drawW = $natW * $scale;
            $drawH = $natH * $scale;
            $logoTop = $this->y + 14; // align logo top with the text cap height
            $this->drawImage($x, $logoTop - $drawH, $drawW, $drawH);
            $leftY = $logoTop - $drawH - 18;
        }

        // Company name
        $companyName = $this->doc['company_name'] ?? '';
        $this->text('F2', 11, $x, $leftY, $companyName, '0.15', '0.15', '0.15');
        $leftY -= 16;

        // Company info lines (left side — address, phone, email only)
        $infoLines = [];
        if ($this->show['address']) {
            $addr1 = trim(($this->doc['company_address1'] ?? '') . ' ' . ($this->doc['company_address2'] ?? ''));
            if ($addr1) $infoLines[] = $addr1;
            // Sender line: "City, Postal" (matches invoice_view.php, which omits
            // the region). array_filter avoids stray/leading commas when parts
            // are empty.
            $cityLine = $this->joinParts([$this->doc['company_city'] ?? '', $this->doc['company_postal'] ?? '']);
            if ($cityLine !== '') $infoLines[] = $cityLine;
        }
        if ($this->show['phone'] && !empty($this->doc['company_phone'])) $infoLines[] = 'Tel: ' . $this->doc['company_phone'];
        if ($this->show['email'] && !empty($this->doc['company_email'])) $infoLines[] = $this->doc['company_email'];
        if ($this->show['website'] && !empty($this->doc['website'])) $infoLines[] = $this->doc['website'];
        foreach ($infoLines as $line) {
            $this->text('F1', 8, $x, $leftY, $line, '0.35', '0.35', '0.35');
            $leftY -= 11;
        }

        // Right side: document type heading + number + dates — matches the
        // on-screen layout where "INVOICE" sits top-right above the number.
        $docType = strtoupper($this->doc['_doc_type'] ?? 'INVOICE');
        $this->textRight('F2', 20, $rightX, $this->y, $docType, $this->headingR, $this->headingG, $this->headingB);

        $docNumber = $this->doc['_doc_title'] ?? '';
        $this->textRight('F2', 11, $rightX, $this->y - 22, $docNumber, $this->textR, $this->textG, $this->textB);

        $rightY = $this->y - 40;
        $dates = $this->doc['_dates'] ?? [];
        // Foreign-currency invoices show a Currency line (matches view/print).
        if ($this->isForeign && !isset($dates['Currency'])) {
            $dates['Currency'] = $this->curCode . ' (' . Currencies::name($this->curCode) . ')';
        }
        foreach ($dates as $label => $value) {
            $this->textRight('F1', 9, $rightX, $rightY, $label . ': ' . $value, '0.35', '0.35', '0.35');
            $rightY -= 13;
        }

        // Registration numbers (right side, below dates)
        $rightY -= 5;
        if ($this->show['vat'] && !empty($this->doc['vat_number'])) {
            $this->textRight('F1', 8, $rightX, $rightY, 'VAT No: ' . $this->doc['vat_number'], '0.45', '0.45', '0.45');
            $rightY -= 11;
        }
        if ($this->show['tax'] && !empty($this->doc['tax_number'])) {
            $this->textRight('F1', 8, $rightX, $rightY, 'Tax No: ' . $this->doc['tax_number'], '0.45', '0.45', '0.45');
            $rightY -= 11;
        }
        if ($this->show['reg'] && !empty($this->doc['reg_number'])) {
            $this->textRight('F1', 8, $rightX, $rightY, 'Reg No: ' . $this->doc['reg_number'], '0.45', '0.45', '0.45');
            $rightY -= 11;
        }

        // Bill To (right side, below status/dates)
        $rightY -= 8;
        $this->textRight('F2', 8, $rightX, $rightY, 'BILL TO', $this->headingR, $this->headingG, $this->headingB);
        $rightY -= 13;
        $this->textRight('F2', 9, $rightX, $rightY, $this->doc['customer_name'] ?? 'Customer', '0.1', '0.1', '0.1');
        $rightY -= 12;
        if (!empty($this->doc['customer_address1'])) {
            $this->textRight('F1', 8, $rightX, $rightY, $this->doc['customer_address1'], '0.35', '0.35', '0.35');
            $rightY -= 11;
        }
        if (!empty($this->doc['customer_address2'])) {
            $this->textRight('F1', 8, $rightX, $rightY, $this->doc['customer_address2'], '0.35', '0.35', '0.35');
            $rightY -= 11;
        }
        $custCity = $this->joinParts([
            $this->doc['customer_city'] ?? '',
            trim(($this->doc['customer_region'] ?? '') . ' ' . ($this->doc['customer_postal'] ?? '')),
        ]);
        if ($custCity !== '') {
            $this->textRight('F1', 8, $rightX, $rightY, $custCity, '0.35', '0.35', '0.35');
            $rightY -= 11;
        }
        if (!empty($this->doc['customer_phone'])) {
            $this->textRight('F1', 8, $rightX, $rightY, 'Tel: ' . $this->doc['customer_phone'], '0.35', '0.35', '0.35');
            $rightY -= 11;
        }
        if (!empty($this->doc['customer_email'])) {
            $this->textRight('F1', 8, $rightX, $rightY, 'Email: ' . $this->doc['customer_email'], '0.35', '0.35', '0.35');
            $rightY -= 11;
        }
        if (!empty($this->doc['customer_vat'])) {
            $this->textRight('F1', 8, $rightX, $rightY, 'VAT No: ' . $this->doc['customer_vat'], '0.35', '0.35', '0.35');
            $rightY -= 11;
        }
        if (!empty($this->doc['customer_reg'])) {
            $this->textRight('F1', 8, $rightX, $rightY, 'Reg No: ' . $this->doc['customer_reg'], '0.35', '0.35', '0.35');
            $rightY -= 11;
        }
        if (!empty($this->doc['project_name'])) {
            $this->textRight('F1', 8, $rightX, $rightY, 'Project: ' . $this->doc['project_name'], '0.35', '0.35', '0.35');
            $rightY -= 11;
        }

        // Accent line — position below whichever column is taller
        $headerBottom = min($leftY, $rightY) - 8;
        $this->line($x, $headerBottom, $rightX, $headerBottom, 3, $this->accentR, $this->accentG, $this->accentB);

        $this->y = $headerBottom - 18;
    }

    private function drawDetails(): void
    {
        // Bill To and Project are now in the header (right side, under status).
        // Nothing to draw here.
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
            // Section heading rows (board-group style): a full-width tinted
            // band with the title in bold — no qty/price/total, and the
            // zebra striping restarts under each heading.
            if (($li['_kind'] ?? 'item') === 'heading') {
                $title = trim((string)($li['item_description'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $headRowH = 24;
                $beforePage = $this->pageNum;
                $this->checkSpace($headRowH + $rowH);
                if ($this->pageNum !== $beforePage) {
                    $this->drawTableHeader($x, $w, $headerH, $colDesc, $colQty, $colPrice, $colTotal);
                    $this->y -= $headerH;
                }
                $this->rect($x, $this->y - $headRowH, $w, $headRowH, '0.93', '0.94', '0.96');
                $this->line($x, $this->y - $headRowH, $x + $w, $this->y - $headRowH, 0.5, '0.8', '0.8', '0.82');
                $this->text('F2', 9.5, $x + 8, $this->y - 16, $title, $this->headingR, $this->headingG, $this->headingB);
                $this->y -= $headRowH;
                $even = false;
                continue;
            }

            $desc = $li['item_description'] ?? '';
            // Estimate row height: wrap long descriptions
            $descLines = $this->wrapText($desc, 9, $colQty - 10);
            $lineRowH = max($rowH, count($descLines) * 13 + 8);

            // Redraw the column header when a row pushes onto a new page, so
            // page 2+ rows are never left with unlabeled number columns.
            $beforePage = $this->pageNum;
            $this->checkSpace($lineRowH);
            if ($this->pageNum !== $beforePage) {
                $this->drawTableHeader($x, $w, $headerH, $colDesc, $colQty, $colPrice, $colTotal);
                $this->y -= $headerH;
            }

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
        $totalRows[] = [$this->taxLabel() . ':', $this->fmt($this->doc['tax'] ?? 0)];

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

    /**
     * Tax row label. The stored tax is computed from each line's rate, so the
     * effective rate is not always 15%. Derive it from tax / taxable base and
     * render the real percentage (e.g. "VAT (0%)", "VAT (14%)").
     */
    private function taxLabel(): string
    {
        $tax  = (float)($this->doc['tax'] ?? 0);
        $base = (float)($this->doc['subtotal'] ?? 0) - (float)($this->doc['discount'] ?? 0);
        $rate = $base > 0 ? ($tax / $base) * 100 : 0.0;
        $rateStr = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
        if ($rateStr === '' || $rateStr === '-0') { $rateStr = '0'; }
        return 'VAT (' . $rateStr . '%)';
    }

    /** Join address parts with a separator, dropping empty/whitespace parts. */
    private function joinParts(array $parts, string $sep = ', '): string
    {
        $clean = array_filter(array_map('trim', $parts), static fn($p) => $p !== '');
        return implode($sep, $clean);
    }

    private function drawMilestones(): void
    {
        if (empty($this->milestones)) return;

        $x = $this->marginL;
        $w = $this->contentW();
        $rowH = 20;
        $headerH = 22;

        $this->checkSpace(60 + $headerH + $rowH);

        // Section title
        $this->text('F2', 10, $x, $this->y, 'Payment Schedule', $this->headingR, $this->headingG, $this->headingB);
        $this->y -= 4;
        $this->line($x, $this->y, $x + $w, $this->y, 0.5, '0.85', '0.85', '0.85');
        $this->y -= 14;

        // Paid summary line — matches the on-screen invoice
        $total   = (float)($this->doc['total'] ?? 0);
        $balance = (float)($this->doc['balance_due'] ?? $total);
        $paid    = max(0, $total - $balance);
        $paidPct = $total > 0 ? ($paid / $total) * 100 : 0;
        $summary = 'Paid ' . $this->fmt($paid) . ' of ' . $this->fmt($total)
                 . ' (' . number_format($paidPct, 1) . '%) - Outstanding ' . $this->fmt($balance);
        $this->text('F1', 8.5, $x, $this->y, $summary, '0.42', '0.44', '0.5');
        $this->y -= 16;

        // Column right/left anchors (fractions of the content width)
        $colPctR   = $x + $w * 0.27;
        $colAmtR   = $x + $w * 0.43;
        $colDueL   = $x + $w * 0.46;
        $colPaidR  = $x + $w * 0.68;
        $colOutR   = $x + $w * 0.84;
        $colStatL  = $x + $w * 0.87;
        // Phase label must not run into the % column — wrap it into this width.
        $phaseW = ($colPctR - 8) - ($x + 8);

        $drawHead = function () use ($x, $w, $headerH, $colPctR, $colAmtR, $colDueL, $colPaidR, $colOutR, $colStatL) {
            $this->rect($x, $this->y - $headerH, $w, $headerH, $this->accentR, $this->accentG, $this->accentB);
            $midY = $this->y - ($headerH * 0.62);
            $this->text('F2', 7.5, $x + 8, $midY, 'PHASE', $this->thTextR, $this->thTextG, $this->thTextB);
            $this->textRight('F2', 7.5, $colPctR, $midY, '%', $this->thTextR, $this->thTextG, $this->thTextB);
            $this->textRight('F2', 7.5, $colAmtR, $midY, 'AMOUNT', $this->thTextR, $this->thTextG, $this->thTextB);
            $this->text('F2', 7.5, $colDueL, $midY, 'DUE DATE', $this->thTextR, $this->thTextG, $this->thTextB);
            $this->textRight('F2', 7.5, $colPaidR, $midY, 'PAID', $this->thTextR, $this->thTextG, $this->thTextB);
            $this->textRight('F2', 7.5, $colOutR, $midY, 'OUTSTANDING', $this->thTextR, $this->thTextG, $this->thTextB);
            $this->text('F2', 7.5, $colStatL, $midY, 'STATUS', $this->thTextR, $this->thTextG, $this->thTextB);
            $this->y -= $headerH;
        };
        $drawHead();

        // First unpaid milestone is "Now Payable" — same rule as the app
        $nowPayableId = null;
        foreach ($this->milestones as $ms) {
            if (($ms['status'] ?? '') !== 'paid') { $nowPayableId = $ms['id'] ?? null; break; }
        }

        $even = false;
        foreach ($this->milestones as $ms) {
            // Wrap the phase label into its column; row grows to fit.
            $labelLines = $this->wrapText((string)($ms['label'] ?? ''), 8, $phaseW);
            $thisRowH = max($rowH, count($labelLines) * 10 + 8);

            $beforePage = $this->pageNum;
            $this->checkSpace($thisRowH);
            if ($this->pageNum !== $beforePage) { $drawHead(); }

            $isNowPayable = ($nowPayableId !== null && ($ms['id'] ?? null) == $nowPayableId);
            if (($ms['status'] ?? '') === 'paid') { $label = 'Paid'; }
            elseif (($ms['status'] ?? '') === 'overdue') { $label = 'Overdue'; }
            elseif ($isNowPayable) { $label = 'Now Payable'; }
            else { $label = 'Upcoming'; }

            if ($isNowPayable) {
                $this->rect($x, $this->y - $thisRowH, $w, $thisRowH, '1', '0.984', '0.922'); // #fffbeb highlight
            } elseif ($even) {
                $this->rect($x, $this->y - $thisRowH, $w, $thisRowH, '0.98', '0.98', '0.99');
            }
            $this->line($x, $this->y - $thisRowH, $x + $w, $this->y - $thisRowH, 0.3, '0.9', '0.9', '0.9');

            $outstanding = max(0, (float)($ms['amount'] ?? 0) - (float)($ms['amount_paid'] ?? 0));
            $due = !empty($ms['due_date']) ? date('d M Y', strtotime($ms['due_date'])) : '-';

            $tY = $this->y - 13;
            $labelY = $tY;
            foreach ($labelLines as $ll) {
                $this->text('F1', 8, $x + 8, $labelY, $ll, '0.1', '0.1', '0.1');
                $labelY -= 10;
            }
            $this->textRight('F1', 8, $colPctR, $tY, number_format((float)($ms['percentage'] ?? 0), 1) . '%', '0.1', '0.1', '0.1');
            $this->textRight('F1', 8, $colAmtR, $tY, $this->fmt($ms['amount'] ?? 0), '0.1', '0.1', '0.1');
            $this->text('F1', 8, $colDueL, $tY, $due, '0.1', '0.1', '0.1');
            $this->textRight('F1', 8, $colPaidR, $tY, $this->fmt($ms['amount_paid'] ?? 0), '0.1', '0.1', '0.1');
            $this->textRight('F1', 8, $colOutR, $tY, $this->fmt($outstanding), '0.1', '0.1', '0.1');
            if ($label === 'Paid') { $this->text('F2', 7.5, $colStatL, $tY, $label, '0.02', '0.37', '0.27'); }
            elseif ($label === 'Overdue') { $this->text('F2', 7.5, $colStatL, $tY, $label, '0.6', '0.11', '0.11'); }
            elseif ($label === 'Now Payable') { $this->text('F2', 7.5, $colStatL, $tY, $label, '0.71', '0.32', '0.04'); }
            else { $this->text('F1', 7.5, $colStatL, $tY, $label, '0.45', '0.45', '0.45'); }

            $this->y -= $thisRowH;
            $even = !$even;
        }

        $this->y -= 18;
    }

    private function drawPayments(): void
    {
        if (empty($this->payments)) return;

        $x = $this->marginL;
        $w = $this->contentW();
        $rowH = 20;
        $headerH = 22;

        $this->checkSpace(40 + $headerH + $rowH);

        // Section title
        $this->text('F2', 10, $x, $this->y, 'Payments Received', $this->headingR, $this->headingG, $this->headingB);
        $this->y -= 4;
        $this->line($x, $this->y, $x + $w, $this->y, 0.5, '0.85', '0.85', '0.85');
        $this->y -= 14;

        $colMethodL = $x + $w * 0.28;
        $colRefL    = $x + $w * 0.50;

        $drawHead = function () use ($x, $w, $headerH, $colMethodL, $colRefL) {
            $this->rect($x, $this->y - $headerH, $w, $headerH, $this->accentR, $this->accentG, $this->accentB);
            $midY = $this->y - ($headerH * 0.62);
            $this->text('F2', 7.5, $x + 8, $midY, 'DATE', $this->thTextR, $this->thTextG, $this->thTextB);
            $this->text('F2', 7.5, $colMethodL, $midY, 'METHOD', $this->thTextR, $this->thTextG, $this->thTextB);
            $this->text('F2', 7.5, $colRefL, $midY, 'REFERENCE', $this->thTextR, $this->thTextG, $this->thTextB);
            $this->textRight('F2', 7.5, $x + $w - 8, $midY, 'AMOUNT', $this->thTextR, $this->thTextG, $this->thTextB);
            $this->y -= $headerH;
        };
        $drawHead();

        $received = 0.0;
        $even = false;
        foreach ($this->payments as $pmt) {
            $beforePage = $this->pageNum;
            $this->checkSpace($rowH);
            if ($this->pageNum !== $beforePage) { $drawHead(); }

            if ($even) {
                $this->rect($x, $this->y - $rowH, $w, $rowH, '0.98', '0.98', '0.99');
            }
            $this->line($x, $this->y - $rowH, $x + $w, $this->y - $rowH, 0.3, '0.9', '0.9', '0.9');

            $date = !empty($pmt['payment_date']) ? date('d M Y', strtotime($pmt['payment_date'])) : '-';
            $ref  = ($pmt['reference'] !== '' && $pmt['reference'] !== null) ? (string)$pmt['reference'] : '-';
            $received += (float)($pmt['amount'] ?? 0);

            $tY = $this->y - 13;
            $this->text('F1', 8, $x + 8, $tY, $date, '0.1', '0.1', '0.1');
            $this->text('F1', 8, $colMethodL, $tY, ucfirst((string)($pmt['method'] ?? '')), '0.1', '0.1', '0.1');
            $this->text('F1', 8, $colRefL, $tY, $ref, '0.1', '0.1', '0.1');
            $this->textRight('F1', 8, $x + $w - 8, $tY, $this->fmt($pmt['amount'] ?? 0), '0.1', '0.1', '0.1');

            $this->y -= $rowH;
            $even = !$even;
        }

        // Totals footer — matches the on-screen table
        $this->checkSpace($rowH * 2);
        $tY = $this->y - 13;
        $this->textRight('F2', 8, $x + $w - 120, $tY, 'Total received', '0.1', '0.1', '0.1');
        $this->textRight('F2', 8, $x + $w - 8, $tY, $this->fmt($received), '0.1', '0.1', '0.1');
        $this->y -= $rowH;
        $this->line($x, $this->y + $rowH - 18, $x + $w, $this->y + $rowH - 18, 0.3, '0.9', '0.9', '0.9');
        $tY = $this->y - 13;
        $this->textRight('F1', 8, $x + $w - 120, $tY, 'Outstanding', '0.3', '0.3', '0.3');
        $this->textRight('F1', 8, $x + $w - 8, $tY, $this->fmt($this->doc['balance_due'] ?? 0), '0.1', '0.1', '0.1');
        $this->y -= $rowH;

        $this->y -= 18;
    }

    private function drawSections(): void
    {
        // Payment Details
        if ($this->show['payment'] && (!empty($this->doc['bank_name']) || !empty($this->doc['bank_account_number']))) {
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

        // NOTE: the invoice "notes" field is an INTERNAL note (labelled
        // "Internal Notes" on the on-screen view) and is deliberately NOT
        // rendered on this customer-facing download/email PDF.
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
        // Per-type footer: download_pdf / senders set _footer_text; fall back to
        // the invoice footer for any legacy caller.
        $footer = $this->doc['_footer_text'] ?? $this->doc['invoice_footer_text'] ?? '';
        if ($footer === '' || $footer === null) return;

        $lines = $this->wrapText((string)$footer, 8, $this->contentW());
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

        // Fonts (base-14, mapped from the company's font choice)
        $fontRegId = $addObj('<< /Type /Font /Subtype /Type1 /BaseFont /' . $this->fontReg . ' /Encoding /WinAnsiEncoding >>');
        $fontBoldId = $addObj('<< /Type /Font /Subtype /Type1 /BaseFont /' . $this->fontBold . ' /Encoding /WinAnsiEncoding >>');

        // Company logo image XObject (+ soft mask for transparency)
        $logoRes = '';
        if ($this->logo) {
            $smaskRef = '';
            if ($this->logo['smask'] !== null) {
                $smaskLen = strlen($this->logo['smask']);
                $smaskId = $addObj(
                    "<< /Type /XObject /Subtype /Image /Width {$this->logo['w']} /Height {$this->logo['h']} " .
                    "/ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length {$smaskLen} >>\n" .
                    "stream\n{$this->logo['smask']}\nendstream"
                );
                $smaskRef = " /SMask {$smaskId} 0 R";
            }
            $imgLen = strlen($this->logo['data']);
            $imgId = $addObj(
                "<< /Type /XObject /Subtype /Image /Width {$this->logo['w']} /Height {$this->logo['h']} " .
                "/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode{$smaskRef} /Length {$imgLen} >>\n" .
                "stream\n{$this->logo['data']}\nendstream"
            );
            $logoRes = " /XObject << /Im1 {$imgId} 0 R >>";
        }

        $pageObjIds = [];
        foreach ($this->pages as $streamContent) {
            $streamLen = strlen($streamContent);
            $contentId = $addObj("<< /Length {$streamLen} >>\nstream\n{$streamContent}endstream");

            $pageId = $addObj(
                "<< /Type /Page /Parent {$pagesId} 0 R " .
                "/MediaBox [0 0 {$this->pageW} {$this->pageH}] " .
                "/Contents {$contentId} 0 R " .
                "/Resources << /Font << /F1 {$fontRegId} 0 R /F2 {$fontBoldId} 0 R >>{$logoRes} >> >>"
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
        // Transcode to WinAnsi first, then escape the PDF string delimiters.
        $s = $this->win($s);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    private function fmt($amount): string
    {
        return $this->curSymbol . ' ' . number_format((float)($amount ?? 0), 2);
    }
}
