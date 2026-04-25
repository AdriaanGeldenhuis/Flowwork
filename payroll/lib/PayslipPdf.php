<?php
// payroll/lib/PayslipPdf.php
//
// Zero-dependency PDF writer for payslips. PDF 1.4 with the built-in
// Helvetica family (no font embedding) so output is small and works on
// any host. The layout below mirrors the HTML payslip template
// (PayslipGenerator.php) so the on-disk PDF and the cover-note email
// attachment look the same as what users see in the browser.

class PayslipPdf
{
    const PAGE_W = 595.28;   // A4 width in points
    const PAGE_H = 841.89;   // A4 height in points
    const MARGIN = 32;       // page side margin

    private $objects = [];
    private $pages = [];
    private $contents = '';
    private $cursorY;
    private $pageNum = 0;

    public function __construct()
    {
        $this->cursorY = 0;
        $this->newPage();
    }

    public function newPage(): void
    {
        if ($this->pageNum > 0) {
            $this->flushPage();
        }
        $this->pageNum++;
        $this->contents = '';
        $this->cursorY = 0;
    }

    public function reserve(float $h): void
    {
        if ($this->cursorY + $h > self::PAGE_H - 24) {
            $this->newPage();
        }
    }

    public function setY(float $y): void { $this->cursorY = $y; }
    public function getY(): float { return $this->cursorY; }
    public function advance(float $dy): void { $this->cursorY += $dy; }

    private function py(float $topY): float
    {
        return self::PAGE_H - $topY;
    }

    private static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.') ?: '0';
    }

    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }

    private static function escape(string $s): string
    {
        $s = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        if ($s === false) $s = '';
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    private static function textWidth(string $s, float $size, bool $bold = false): float
    {
        // Average glyph width ratios for Helvetica @ 1pt
        $factor = $bold ? 0.555 : 0.512;
        $clean = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s) ?: '';
        return strlen($clean) * $size * $factor;
    }

    public function fillRect(float $x, float $topY, float $w, float $h, string $hex): void
    {
        [$r, $g, $b] = self::rgb($hex);
        $y = $this->py($topY) - $h;
        $this->contents .= sprintf("%s %s %s rg\n%s %s %s %s re\nf\n",
            self::fmt($r), self::fmt($g), self::fmt($b),
            self::fmt($x), self::fmt($y), self::fmt($w), self::fmt($h));
    }

    public function line(float $x1, float $topY1, float $x2, float $topY2, string $hex = '#e5e7eb', float $width = 0.5): void
    {
        [$r, $g, $b] = self::rgb($hex);
        $this->contents .= sprintf("%s %s %s RG\n%s w\n%s %s m %s %s l S\n",
            self::fmt($r), self::fmt($g), self::fmt($b),
            self::fmt($width),
            self::fmt($x1), self::fmt($this->py($topY1)),
            self::fmt($x2), self::fmt($this->py($topY2)));
    }

    public function text(float $x, float $topY, string $str, float $size = 9, bool $bold = false, string $hex = '#1f2937'): void
    {
        if ($str === '') return;
        [$r, $g, $b] = self::rgb($hex);
        $font = $bold ? 'F2' : 'F1';
        $this->contents .= sprintf("BT\n/%s %s Tf\n%s %s %s rg\n%s %s Td\n(%s) Tj\nET\n",
            $font, self::fmt($size),
            self::fmt($r), self::fmt($g), self::fmt($b),
            self::fmt($x), self::fmt($this->py($topY) - $size * 0.85),
            self::escape($str));
    }

    public function textRight(float $rightX, float $topY, string $str, float $size = 9, bool $bold = false, string $hex = '#1f2937'): void
    {
        $w = self::textWidth($str, $size, $bold);
        $this->text($rightX - $w, $topY, $str, $size, $bold, $hex);
    }

    public function textCenter(float $cx, float $topY, string $str, float $size = 9, bool $bold = false, string $hex = '#1f2937'): void
    {
        $w = self::textWidth($str, $size, $bold);
        $this->text($cx - $w / 2, $topY, $str, $size, $bold, $hex);
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

        // Reserve IDs accounting for the page objects that get appended below.
        // Layout: [content streams already added] [page objects] catalog pages F1 F2.
        $afterPages = count($this->objects) + count($this->pages);
        $catalogId  = $afterPages + 1;
        $pagesId    = $catalogId + 1;
        $fontF1Id   = $pagesId + 1;
        $fontF2Id   = $fontF1Id + 1;

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

        $kids = implode(' ', array_map(fn($id) => "$id 0 R", $pageIds));
        $this->addObject("<< /Type /Catalog /Pages " . $pagesId . " 0 R >>");
        $this->addObject("<< /Type /Pages /Kids [" . $kids . "] /Count " . count($pageIds) . " >>");
        $this->addObject("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>");
        $this->addObject("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>");

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
 * Render a payslip PDF that mirrors the HTML payslip layout.
 */
function renderPayslipPdf(array $company, array $run, array $emp, array $lines, array $ytd, string $taxYearStart): string
{
    $primary  = $company['primary_color'] ?? '#f97316';
    $textOnP  = '#ffffff';
    $cName    = $company['name'] ?? 'Company';
    $empName  = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));

    $money = static fn ($cents) => 'R ' . number_format(((int)$cents) / 100, 2, '.', ' ');

    $earningRows  = [];
    $deductionRows = [];
    foreach ($lines as $li) {
        $type = strtolower((string)($li['type'] ?? 'earning'));
        $row  = [
            'name' => $li['item_name'] ?: ($li['description'] ?? ''),
            'qty'  => (float)($li['qty'] ?? 1),
            'rate' => (int)($li['rate_cents'] ?? 0),
            'amt'  => (int)($li['amount_cents'] ?? 0),
        ];
        if ($type === 'deduction') {
            $deductionRows[] = $row;
        } else {
            $earningRows[] = $row;
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

    $taxYearLabel = date('Y', strtotime($taxYearStart)) . '/' . (date('Y', strtotime($taxYearStart)) + 1);

    $pdf = new PayslipPdf();
    $L = PayslipPdf::MARGIN;            // left margin x
    $R = PayslipPdf::PAGE_W - PayslipPdf::MARGIN; // right margin x
    $W = $R - $L;                       // usable width

    // ---------- Header band (full-bleed) ----------
    $headerH = 130;
    $pdf->fillRect(0, 0, PayslipPdf::PAGE_W, $headerH, $primary);

    // Company name
    $pdf->text($L, 26, $cName, 18, true, $textOnP);

    // Address + contact lines
    $y = 50;
    $companyLines = array_filter([
        $company['address_line1'] ?? '',
        $company['address_line2'] ?? '',
        trim(($company['city'] ?? '') . ' ' . ($company['postal'] ?? '')),
        $company['region'] ?? '',
        !empty($company['phone']) ? 'Tel: ' . $company['phone'] : '',
        $company['email'] ?? '',
        $company['website'] ?? '',
    ]);
    foreach ($companyLines as $line) {
        if ($y > $headerH - 18) break;
        $pdf->text($L, $y, $line, 8.5, false, $textOnP);
        $y += 11;
    }
    $refs = array_filter([
        !empty($company['reg_number']) ? 'Reg: ' . $company['reg_number'] : '',
        !empty($company['tax_number']) ? 'PAYE Ref: ' . $company['tax_number'] : '',
        !empty($company['vat_number']) ? 'VAT: ' . $company['vat_number'] : '',
    ]);
    if ($refs) {
        $pdf->text($L, $y + 2, implode('   |   ', $refs), 8, false, $textOnP);
    }

    // Right side: PAYSLIP label, big title, run number, period
    $pdf->textRight($R, 22, 'PAYSLIP', 9, true, $textOnP);
    $pdf->textRight($R, 50, 'PAYSLIP', 26, true, $textOnP);
    $pdf->textRight($R, 80, $run['run_number'] ?? '', 9.5, false, $textOnP);
    $period = date('d M Y', strtotime($run['period_start'])) . ' – ' . date('d M Y', strtotime($run['period_end']));
    $pdf->textRight($R, 96, $period, 9.5, false, $textOnP);

    $pdf->setY($headerH + 22);

    // ---------- Employee details (4-column grid) ----------
    $kvCols = 4;
    $kvColW = $W / $kvCols;
    $kvRowH = 36;
    $kv = [
        ['EMPLOYEE',      $empName ?: '—'],
        ['EMPLOYEE NO.',  $emp['employee_no'] ?? '—'],
        ['ID NUMBER',     $emp['id_number'] ?? '—'],
        ['TAX NUMBER',    $emp['tax_number'] ?? '—'],
        ['HIRE DATE',     !empty($emp['hire_date']) ? date('d M Y', strtotime($emp['hire_date'])) : '—'],
        ['PAY FREQUENCY', ucfirst((string)($emp['pay_frequency'] ?? '—'))],
        ['PAY DATE',      date('d M Y', strtotime($run['pay_date']))],
        ['TAX YEAR',      $taxYearLabel],
    ];
    $startY = $pdf->getY();
    foreach ($kv as $i => $item) {
        $col = $i % $kvCols;
        $row = (int)($i / $kvCols);
        $x = $L + $col * $kvColW;
        $rowY = $startY + $row * $kvRowH;
        $pdf->text($x, $rowY, $item[0], 7.2, true, '#6b7280');
        $pdf->text($x, $rowY + 12, (string)$item[1], 11, true, '#111827');
    }
    $pdf->setY($startY + ceil(count($kv) / $kvCols) * $kvRowH + 10);

    // ---------- Earnings + Deductions side by side ----------
    $gap = 16;
    $colW = ($W - $gap) / 2;
    $earnX = $L;
    $dedX  = $L + $colW + $gap;

    $secY = $pdf->getY();
    $pdf->text($earnX, $secY, 'EARNINGS',   9.5, true, $primary);
    $pdf->text($dedX,  $secY, 'DEDUCTIONS', 9.5, true, $primary);

    // Header row
    $hdrY = $secY + 18;
    $pdf->text($earnX, $hdrY, 'DESCRIPTION', 7, true, '#6b7280');
    $pdf->textRight($earnX + $colW * 0.55, $hdrY, 'QTY', 7, true, '#6b7280');
    $pdf->textRight($earnX + $colW * 0.78, $hdrY, 'RATE', 7, true, '#6b7280');
    $pdf->textRight($earnX + $colW,        $hdrY, 'AMOUNT', 7, true, '#6b7280');
    $pdf->text($dedX, $hdrY, 'DESCRIPTION', 7, true, '#6b7280');
    $pdf->textRight($dedX + $colW, $hdrY, 'AMOUNT', 7, true, '#6b7280');
    $pdf->line($earnX, $hdrY + 6, $earnX + $colW, $hdrY + 6, '#e5e7eb', 0.6);
    $pdf->line($dedX,  $hdrY + 6, $dedX  + $colW, $hdrY + 6, '#e5e7eb', 0.6);

    $rowY = $hdrY + 14;
    $rowH = 16;

    // Earnings rows
    $eY = $rowY;
    foreach ($earningRows as $r) {
        $pdf->text($earnX, $eY, $r['name'], 9.5, false, '#1f2937');
        $qtyStr = $r['qty'] == floor($r['qty']) ? (string)(int)$r['qty'] : number_format($r['qty'], 2);
        $pdf->textRight($earnX + $colW * 0.55, $eY, $qtyStr, 9.5, false, '#1f2937');
        $pdf->textRight($earnX + $colW * 0.78, $eY, $money($r['rate']), 9.5, false, '#1f2937');
        $pdf->textRight($earnX + $colW, $eY, $money($r['amt']), 9.5, false, '#1f2937');
        $eY += $rowH;
        $pdf->line($earnX, $eY - 4, $earnX + $colW, $eY - 4, '#f1f2f4', 0.3);
    }
    if ($reimb > 0) {
        $pdf->text($earnX, $eY, 'Reimbursements', 9.5, false, '#1f2937');
        $pdf->textRight($earnX + $colW, $eY, $money($reimb), 9.5, false, '#1f2937');
        $eY += $rowH;
        $pdf->line($earnX, $eY - 4, $earnX + $colW, $eY - 4, '#f1f2f4', 0.3);
    }
    // Gross + Taxable
    $pdf->line($earnX, $eY - 2, $earnX + $colW, $eY - 2, '#cbd5e1', 0.8);
    $eY += 4;
    $pdf->text($earnX, $eY, 'Gross Earnings', 10, true, '#111827');
    $pdf->textRight($earnX + $colW, $eY, $money($gross + $reimb), 10, true, '#111827');
    $eY += $rowH;
    $pdf->text($earnX, $eY, 'Taxable Income', 8.5, false, '#6b7280');
    $pdf->textRight($earnX + $colW, $eY, $money($taxable), 8.5, false, '#6b7280');
    $eY += $rowH;

    // Deductions rows
    $dY = $rowY;
    $dRows = array_merge(
        [['name' => 'PAYE (Income Tax)', 'amt' => $paye],
         ['name' => 'UIF (Employee 1%)', 'amt' => $uifEmp]],
        array_map(fn ($r) => ['name' => $r['name'], 'amt' => $r['amt']], $deductionRows)
    );
    if ($otherDed > 0 && !$deductionRows) {
        $dRows[] = ['name' => 'Other Deductions', 'amt' => $otherDed];
    }
    foreach ($dRows as $r) {
        $pdf->text($dedX, $dY, $r['name'], 9.5, false, '#1f2937');
        $pdf->textRight($dedX + $colW, $dY, $money($r['amt']), 9.5, false, '#1f2937');
        $dY += $rowH;
        $pdf->line($dedX, $dY - 4, $dedX + $colW, $dY - 4, '#f1f2f4', 0.3);
    }
    $pdf->line($dedX, $dY - 2, $dedX + $colW, $dY - 2, '#cbd5e1', 0.8);
    $dY += 4;
    $totalDed = $paye + $uifEmp + $otherDed + array_sum(array_column($deductionRows, 'amt'));
    $pdf->text($dedX, $dY, 'Total Deductions', 10, true, '#111827');
    $pdf->textRight($dedX + $colW, $dY, $money($totalDed), 10, true, '#111827');
    $dY += $rowH;

    $pdf->setY(max($eY, $dY) + 10);

    // ---------- Net Pay band ----------
    $netY = $pdf->getY();
    $netH = 44;
    $pdf->fillRect($L, $netY, $W, $netH, $primary);
    $pdf->text($L + 18, $netY + 18, 'NET PAY', 11, true, $textOnP);
    $pdf->textRight($R - 18, $netY + 14, $money($net), 22, true, $textOnP);
    $pdf->setY($netY + $netH + 16);

    // ---------- Employer Contributions (3-column grid in light box) ----------
    $ecY = $pdf->getY();
    $pdf->fillRect($L, $ecY, $W, 60, '#fafbfc');
    $pdf->text($L + 14, $ecY + 12, 'EMPLOYER CONTRIBUTIONS', 8.5, true, '#6b7280');
    $pdf->text($L + 14 + 165, $ecY + 12, '(not deducted from net pay)', 8, false, '#9ca3af');

    $ec = [
        ['UIF (EMPLOYER 1%)',     $money($uifEmpr)],
        ['SDL (1%)',              $money($sdl)],
        ['TOTAL COST TO COMPANY', $money($emprCost ?: ($gross + $uifEmpr + $sdl))],
    ];
    $ecCols = count($ec);
    $ecColW = $W / $ecCols;
    foreach ($ec as $i => $item) {
        $x = $L + $i * $ecColW + 14;
        $pdf->text($x, $ecY + 30, $item[0], 7.2, true, '#6b7280');
        $pdf->text($x, $ecY + 42, $item[1], 11, true, '#111827');
    }
    $pdf->setY($ecY + 60 + 16);

    // ---------- Year to Date table ----------
    $ytdY = $pdf->getY();
    $pdf->text($L, $ytdY, 'YEAR TO DATE (' . $taxYearLabel . ')', 9.5, true, $primary);
    $hdrY = $ytdY + 16;
    $pdf->fillRect($L, $hdrY - 4, $W, 18, '#fafbfc');
    $pdf->text($L + 8, $hdrY + 6, 'ITEM', 7, true, '#6b7280');
    $pdf->textRight($R - 8, $hdrY + 6, 'YTD AMOUNT', 7, true, '#6b7280');

    $ytdItems = [
        ['Gross Earnings',  $money((int)($ytd['gross']    ?? 0))],
        ['Taxable Income',  $money((int)($ytd['taxable']  ?? 0))],
        ['PAYE',            $money((int)($ytd['paye']     ?? 0))],
        ['UIF (Employee)',  $money((int)($ytd['uif_emp']  ?? 0))],
        ['UIF (Employer)',  $money((int)($ytd['uif_empr'] ?? 0))],
        ['SDL',             $money((int)($ytd['sdl']      ?? 0))],
    ];
    $rY = $hdrY + 22;
    foreach ($ytdItems as $r) {
        $pdf->text($L + 8, $rY, $r[0], 9.5, false, '#1f2937');
        $pdf->textRight($R - 8, $rY, $r[1], 9.5, false, '#1f2937');
        $rY += 16;
        $pdf->line($L, $rY - 4, $R, $rY - 4, '#f1f2f4', 0.3);
    }
    $pdf->line($L, $rY - 2, $R, $rY - 2, '#cbd5e1', 0.8);
    $rY += 4;
    $pdf->text($L + 8, $rY, 'Net Paid', 10, true, '#111827');
    $pdf->textRight($R - 8, $rY, $money((int)($ytd['net'] ?? 0)), 10, true, '#111827');
    $pdf->setY($rY + 22);

    // ---------- Footer ----------
    $footerY = max($pdf->getY(), PayslipPdf::PAGE_H - 30);
    $pdf->textCenter(PayslipPdf::PAGE_W / 2, $footerY,
        'This is a computer-generated payslip and is valid without a signature. Generated ' . date('d M Y H:i') . '.',
        8, false, '#9ca3af');

    return $pdf->output();
}
