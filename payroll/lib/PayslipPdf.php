<?php
// payroll/lib/PayslipPdf.php
//
// Tiny zero-dependency PDF writer focused on payslip output. Uses PDF 1.4
// with the built-in Helvetica family so no font embedding is needed and
// the output stays small (~3-5 KB per slip). A4 portrait, top-down cursor.
//
// Public surface:
//   $pdf = new PayslipPdf();
//   $pdf->headerBand($title, $companyName, $primaryHex);
//   $pdf->kvGrid([...]);
//   $pdf->table([cols], [rows], [aligns]);
//   $pdf->netBox($amount, $primaryHex);
//   $bytes = $pdf->output();
//
// renderPayslipPdf() is the high-level helper that mirrors the HTML
// payslip layout and is what PayslipEmailer attaches to outgoing mail.

class PayslipPdf
{
    const PAGE_W = 595.28;   // A4 width in points
    const PAGE_H = 841.89;   // A4 height in points
    const MARGIN_X = 36;     // 0.5"
    const MARGIN_TOP = 36;
    const MARGIN_BOTTOM = 40;

    private $objects = [];   // 1-indexed: id => raw object body (without "N 0 obj")
    private $pages   = [];   // page object ids
    private $contents = '';  // current page content stream
    private $cursorY;        // points from top of page (0 = top); converted on draw
    private $pageNum = 0;

    public function __construct()
    {
        $this->cursorY = self::MARGIN_TOP;
        $this->newPage();
    }

    public function newPage(): void
    {
        if ($this->pageNum > 0) {
            $this->flushPage();
        }
        $this->pageNum++;
        $this->contents = '';
        $this->cursorY = self::MARGIN_TOP;
    }

    /** Move the cursor down by $dy points. */
    public function advance(float $dy): void
    {
        $this->cursorY += $dy;
        if ($this->cursorY > self::PAGE_H - self::MARGIN_BOTTOM - 20) {
            $this->newPage();
        }
    }

    public function reserve(float $dy): void
    {
        if ($this->cursorY + $dy > self::PAGE_H - self::MARGIN_BOTTOM) {
            $this->newPage();
        }
    }

    /** Convert top-origin Y to PDF bottom-origin Y. */
    private function py(float $topY): float
    {
        return self::PAGE_H - $topY;
    }

    private static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }

    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;
        return [$r, $g, $b];
    }

    private static function escape(string $s): string
    {
        // Convert UTF-8 -> WinAnsi (CP1252) so the standard fonts can render it
        $s = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        if ($s === false) $s = '';
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    /** Approximate width in points for Helvetica/Helvetica-Bold at $size pt. */
    private static function textWidth(string $s, float $size, bool $bold = false): float
    {
        // Rough average glyph width factor for Helvetica
        $factor = $bold ? 0.56 : 0.52;
        $clean = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s) ?: '';
        return strlen($clean) * $size * $factor;
    }

    public function fillRect(float $x, float $topY, float $w, float $h, string $hex): void
    {
        [$r, $g, $b] = self::rgb($hex);
        $y = $this->py($topY) - $h;
        $this->contents .= sprintf("%s %s %s rg\n%s %s %s %s re\nf\n",
            self::fmt($r), self::fmt($g), self::fmt($b),
            self::fmt($x), self::fmt($y), self::fmt($w), self::fmt($h)
        );
    }

    public function line(float $x1, float $topY1, float $x2, float $topY2, string $hex = '#e5e7eb', float $width = 0.5): void
    {
        [$r, $g, $b] = self::rgb($hex);
        $this->contents .= sprintf("%s %s %s RG\n%s w\n%s %s m %s %s l S\n",
            self::fmt($r), self::fmt($g), self::fmt($b),
            self::fmt($width),
            self::fmt($x1), self::fmt($this->py($topY1)),
            self::fmt($x2), self::fmt($this->py($topY2))
        );
    }

    public function text(float $x, float $topY, string $str, float $size = 9, bool $bold = false, string $hex = '#1f2937'): void
    {
        [$r, $g, $b] = self::rgb($hex);
        $font = $bold ? 'F2' : 'F1';
        $this->contents .= sprintf("BT\n/%s %s Tf\n%s %s %s rg\n%s %s Td\n(%s) Tj\nET\n",
            $font, self::fmt($size),
            self::fmt($r), self::fmt($g), self::fmt($b),
            self::fmt($x), self::fmt($this->py($topY) - $size * 0.85),
            self::escape($str)
        );
    }

    public function textRight(float $rightX, float $topY, string $str, float $size = 9, bool $bold = false, string $hex = '#1f2937'): void
    {
        $w = self::textWidth($str, $size, $bold);
        $this->text($rightX - $w, $topY, $str, $size, $bold, $hex);
    }

    /** Coloured band across the top of the page with company name + PAYSLIP. */
    public function headerBand(string $companyName, string $subtitle, string $primaryHex): void
    {
        $h = 70;
        $this->fillRect(0, 0, self::PAGE_W, $h, $primaryHex);
        $this->text(self::MARGIN_X, 22, $companyName, 18, true, '#ffffff');
        $this->text(self::MARGIN_X, 44, $subtitle, 9.5, false, '#ffffff');
        $this->textRight(self::PAGE_W - self::MARGIN_X, 22, 'PAYSLIP', 22, true, '#ffffff');
        $this->cursorY = $h + 18;
    }

    public function sectionTitle(string $title, string $primaryHex): void
    {
        $this->reserve(28);
        $this->text(self::MARGIN_X, $this->cursorY, strtoupper($title), 8.5, true, $primaryHex);
        $this->cursorY += 12;
        $this->line(self::MARGIN_X, $this->cursorY, self::PAGE_W - self::MARGIN_X, $this->cursorY, '#e5e7eb', 0.5);
        $this->cursorY += 10;
    }

    /** 4-column key/value grid. Items: [['Label', 'Value'], ...] */
    public function kvGrid(array $items, int $cols = 4): void
    {
        $usable = self::PAGE_W - 2 * self::MARGIN_X;
        $colW = $usable / $cols;
        $rowH = 28;
        $i = 0;
        foreach ($items as $item) {
            $col = $i % $cols;
            if ($col === 0 && $i > 0) {
                $this->cursorY += $rowH;
                $this->reserve($rowH);
            }
            $x = self::MARGIN_X + $col * $colW;
            $this->text($x, $this->cursorY, strtoupper($item[0]), 7, true, '#6b7280');
            $this->text($x, $this->cursorY + 11, (string)$item[1], 10, false, '#111827');
            $i++;
        }
        $this->cursorY += $rowH + 4;
    }

    /**
     * Two-column section: earnings on the left, deductions on the right.
     * Each side is ['title' => str, 'rows' => [[label, value, isBold?], ...]]
     */
    public function twoColumnTables(array $left, array $right, string $primaryHex): void
    {
        $usable = self::PAGE_W - 2 * self::MARGIN_X;
        $gap = 14;
        $colW = ($usable - $gap) / 2;
        $rowH = 16;
        $headH = 22;

        $leftRows = $left['rows'] ?? [];
        $rightRows = $right['rows'] ?? [];
        $maxRows = max(count($leftRows), count($rightRows));
        $blockH = $headH + $maxRows * $rowH + 8;
        $this->reserve($blockH);

        $startY = $this->cursorY;

        // Headers
        $this->text(self::MARGIN_X, $startY, strtoupper($left['title']), 8.5, true, $primaryHex);
        $this->text(self::MARGIN_X + $colW + $gap, $startY, strtoupper($right['title']), 8.5, true, $primaryHex);

        $headerLineY = $startY + 12;
        $this->line(self::MARGIN_X, $headerLineY, self::MARGIN_X + $colW, $headerLineY, '#e5e7eb', 0.5);
        $this->line(self::MARGIN_X + $colW + $gap, $headerLineY, self::PAGE_W - self::MARGIN_X, $headerLineY, '#e5e7eb', 0.5);

        $rowY = $startY + $headH;
        for ($i = 0; $i < $maxRows; $i++) {
            $this->renderTableRow(self::MARGIN_X, $rowY, $colW, $leftRows[$i] ?? null);
            $this->renderTableRow(self::MARGIN_X + $colW + $gap, $rowY, $colW, $rightRows[$i] ?? null);
            $rowY += $rowH;
            if ($i < $maxRows - 1) {
                $this->line(self::MARGIN_X, $rowY - 4, self::MARGIN_X + $colW, $rowY - 4, '#f1f2f4', 0.3);
                $this->line(self::MARGIN_X + $colW + $gap, $rowY - 4, self::PAGE_W - self::MARGIN_X, $rowY - 4, '#f1f2f4', 0.3);
            }
        }

        $this->cursorY = $rowY + 4;
    }

    private function renderTableRow(float $x, float $y, float $w, ?array $row): void
    {
        if (!$row) return;
        $bold = !empty($row[2]);
        $color = $bold ? '#111827' : '#374151';
        $this->text($x, $y, (string)$row[0], 9.5, $bold, $color);
        $this->textRight($x + $w, $y, (string)$row[1], 9.5, $bold, $color);
    }

    /** Single-column simple table. $rows = [[label, value, isBold?], ...] */
    public function table(string $title, array $rows, string $primaryHex): void
    {
        $rowH = 16;
        $this->reserve(24 + count($rows) * $rowH);
        $this->sectionTitle($title, $primaryHex);
        $usable = self::PAGE_W - 2 * self::MARGIN_X;
        foreach ($rows as $r) {
            $bold = !empty($r[2]);
            $color = $bold ? '#111827' : '#374151';
            $this->text(self::MARGIN_X, $this->cursorY, (string)$r[0], 9.5, $bold, $color);
            $this->textRight(self::PAGE_W - self::MARGIN_X, $this->cursorY, (string)$r[1], 9.5, $bold, $color);
            $this->cursorY += $rowH;
            $this->line(self::MARGIN_X, $this->cursorY - 4, self::PAGE_W - self::MARGIN_X, $this->cursorY - 4, '#f1f2f4', 0.3);
        }
        $this->cursorY += 4;
    }

    public function netBox(string $netAmount, string $primaryHex): void
    {
        $h = 40;
        $this->reserve($h + 14);
        $this->cursorY += 4;
        $this->fillRect(self::MARGIN_X, $this->cursorY, self::PAGE_W - 2 * self::MARGIN_X, $h, $primaryHex);
        $this->text(self::MARGIN_X + 14, $this->cursorY + 14, 'NET PAY', 11, true, '#ffffff');
        $this->textRight(self::PAGE_W - self::MARGIN_X - 14, $this->cursorY + 12, $netAmount, 18, true, '#ffffff');
        $this->cursorY += $h + 14;
    }

    public function footer(string $text): void
    {
        $y = self::PAGE_H - 24;
        $w = self::textWidth($text, 8);
        $this->text((self::PAGE_W - $w) / 2, $y, $text, 8, false, '#9ca3af');
    }

    private function flushPage(): void
    {
        $stream = $this->contents;
        $contentObj = $this->addObject(
            "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream"
        );
        $this->pages[] = ['content' => $contentObj];
    }

    private function addObject(string $body): int
    {
        $this->objects[] = $body;
        return count($this->objects);
    }

    public function output(): string
    {
        $this->flushPage();

        // Reserve IDs for catalog, pages tree, fonts
        $catalogId = count($this->objects) + 1;
        $pagesId   = $catalogId + 1;
        $fontF1Id  = $pagesId + 1;
        $fontF2Id  = $fontF1Id + 1;

        // Build page objects, each referencing pages tree as parent
        $pageIds = [];
        foreach ($this->pages as $p) {
            $pageBody = sprintf(
                "<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> /Contents %d 0 R >>",
                $pagesId,
                self::fmt(self::PAGE_W), self::fmt(self::PAGE_H),
                $fontF1Id, $fontF2Id,
                $p['content']
            );
            $pageIds[] = $this->addObject($pageBody);
        }

        // Pages tree
        $kids = implode(' ', array_map(fn($id) => "$id 0 R", $pageIds));
        $this->addObject(
            "<< /Type /Catalog /Pages " . $pagesId . " 0 R >>"
        ); // catalog (id = $catalogId)
        $this->addObject(
            "<< /Type /Pages /Kids [" . $kids . "] /Count " . count($pageIds) . " >>"
        ); // pages (id = $pagesId)
        $this->addObject(
            "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>"
        ); // F1
        $this->addObject(
            "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>"
        ); // F2

        // Build the file
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($this->objects as $i => $body) {
            $id = $i + 1;
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }
        $xrefOffset = strlen($out);
        $out .= "xref\n0 " . (count($this->objects) + 1) . "\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($this->objects); $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size " . (count($this->objects) + 1) . " /Root $catalogId 0 R >>\n";
        $out .= "startxref\n$xrefOffset\n%%EOF\n";
        return $out;
    }
}

/**
 * Render a payslip PDF for one employee. Mirrors the HTML payslip layout
 * but produces an A4 PDF that can be attached to email.
 */
function renderPayslipPdf(array $company, array $run, array $emp, array $lines, array $ytd, string $taxYearStart): string
{
    $primary = $company['primary_color'] ?? '#f97316';
    $companyName = $company['name'] ?? 'Company';
    $empName = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));

    $money = static function ($cents) {
        return 'R ' . number_format(((int)$cents) / 100, 2, '.', ' ');
    };

    $earningRows  = [];
    $deductionRows = [];
    foreach ($lines as $li) {
        $type = strtolower((string)($li['type'] ?? 'earning'));
        $name = $li['item_name'] ?: ($li['description'] ?? '');
        $amt  = (int)($li['amount_cents'] ?? 0);
        if ($type === 'deduction') {
            $deductionRows[] = [$name, $money($amt)];
        } else {
            $earningRows[] = [$name, $money($amt)];
        }
    }

    $gross    = (int)($emp['gross_cents'] ?? 0);
    $taxable  = (int)($emp['taxable_income_cents'] ?? 0);
    $paye     = (int)($emp['paye_cents'] ?? 0);
    $uifEmp   = (int)($emp['uif_employee_cents'] ?? 0);
    $uifEmpr  = (int)($emp['uif_employer_cents'] ?? 0);
    $sdl      = (int)($emp['sdl_cents'] ?? 0);
    $otherDed = (int)($emp['other_deductions_cents'] ?? 0);
    $reimb    = (int)($emp['reimbursements_cents'] ?? 0);
    $net      = (int)($emp['net_cents'] ?? 0);
    $emprCost = (int)($emp['employer_cost_cents'] ?? 0);

    if ($reimb > 0) {
        $earningRows[] = ['Reimbursements', $money($reimb)];
    }
    $earningRows[] = ['Gross Earnings', $money($gross + $reimb), true];

    array_unshift($deductionRows, ['UIF (Employee 1%)', $money($uifEmp)]);
    array_unshift($deductionRows, ['PAYE (Income Tax)', $money($paye)]);
    if ($otherDed > 0 && empty($deductionRows)) {
        $deductionRows[] = ['Other Deductions', $money($otherDed)];
    }
    $deductionRows[] = ['Total Deductions', $money($paye + $uifEmp + $otherDed), true];

    $taxYearLabel = date('Y', strtotime($taxYearStart)) . '/' .
                    (date('Y', strtotime($taxYearStart)) + 1);

    $pdf = new PayslipPdf();

    $subtitle = trim(implode('  ·  ', array_filter([
        ($company['address_line1'] ?? '') . ', ' . ($company['city'] ?? '') . ' ' . ($company['postal'] ?? ''),
        !empty($company['phone']) ? 'Tel ' . $company['phone'] : '',
        !empty($company['reg_number']) ? 'Reg ' . $company['reg_number'] : '',
        !empty($company['tax_number']) ? 'PAYE Ref ' . $company['tax_number'] : '',
    ])), ', ');

    $pdf->headerBand($companyName, $subtitle, $primary);

    $pdf->sectionTitle($run['name'] . '  -  ' . $run['run_number'], $primary);
    $pdf->kvGrid([
        ['Period Start', date('d M Y', strtotime($run['period_start']))],
        ['Period End',   date('d M Y', strtotime($run['period_end']))],
        ['Pay Date',     date('d M Y', strtotime($run['pay_date']))],
        ['Tax Year',     $taxYearLabel],
    ], 4);

    $pdf->sectionTitle('Employee', $primary);
    $pdf->kvGrid([
        ['Name',          $empName ?: '-'],
        ['Employee No.',  $emp['employee_no'] ?? '-'],
        ['ID Number',     $emp['id_number'] ?? '-'],
        ['Tax Number',    $emp['tax_number'] ?? '-'],
        ['Hire Date',     !empty($emp['hire_date']) ? date('d M Y', strtotime($emp['hire_date'])) : '-'],
        ['Pay Frequency', ucfirst((string)($emp['pay_frequency'] ?? '-'))],
        ['Bank',          trim(($emp['bank_name'] ?? '') . ' ' . ($emp['bank_account_no'] ?? '')) ?: '-'],
        ['Branch Code',   $emp['branch_code'] ?? '-'],
    ], 4);

    $pdf->twoColumnTables(
        ['title' => 'Earnings',   'rows' => $earningRows],
        ['title' => 'Deductions', 'rows' => $deductionRows],
        $primary
    );

    $pdf->netBox($money($net), $primary);

    $pdf->table('Employer Contributions (not deducted from net)', [
        ['UIF (Employer 1%)',     $money($uifEmpr)],
        ['SDL (1%)',              $money($sdl)],
        ['Total Cost to Company', $money($emprCost ?: ($gross + $uifEmpr + $sdl)), true],
    ], $primary);

    $pdf->table('Year to Date (' . $taxYearLabel . ')', [
        ['Gross Earnings',  $money((int)($ytd['gross']    ?? 0))],
        ['Taxable Income',  $money((int)($ytd['taxable']  ?? 0))],
        ['PAYE',            $money((int)($ytd['paye']     ?? 0))],
        ['UIF (Employee)',  $money((int)($ytd['uif_emp']  ?? 0))],
        ['UIF (Employer)',  $money((int)($ytd['uif_empr'] ?? 0))],
        ['SDL',             $money((int)($ytd['sdl']      ?? 0))],
        ['Net Paid YTD',    $money((int)($ytd['net']      ?? 0)), true],
    ], $primary);

    $pdf->footer('Computer-generated payslip · valid without signature · ' . date('d M Y H:i'));

    return $pdf->output();
}
