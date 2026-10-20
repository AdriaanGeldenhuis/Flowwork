<?php
// payroll/lib/PayslipPdf.php
//
// Zero-dependency PDF writer for payslips. PDF 1.4 with the built-in
// Helvetica family (no font embedding) so output is small and works on
// any host. renderPayslipPdf() lays out one payslip; PayPayslips decides
// what goes on it, where it is stored and who it is emailed to.

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
 * One payslip.
 *   $company  companies row (+ paye_reference from the payroll settings)
 *   $run      pay_runs row
 *   $emp      pay_run_employees row joined with the employee
 *   $lines    pay_run_lines with the pay item's type
 *   $ytd      tax-year-to-date totals from finalised runs
 *   $draft    true while the run is not finalised: a "DRAFT" band is printed
 */
function renderPayslipPdf(array $company, array $run, array $emp, array $lines, array $ytd, string $taxYearStart, bool $draft = false): string
{
    $primary = preg_match('/^#[0-9a-f]{6}$/i', (string)($company['primary_color'] ?? '')) ? $company['primary_color'] : '#a4552c';
    $onP = '#ffffff';
    $ink = '#1f2937';
    $muted = '#6b7280';
    $rule = '#e5e7eb';
    $cName = (string)($company['name'] ?? 'Company');
    $empName = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
    $money = static fn($cents) => 'R ' . number_format(((int)$cents) / 100, 2, '.', ' ');
    $d = static fn($s) => $s ? date('j M Y', strtotime($s)) : '—';

    // Sort the lines the way a person reads a payslip
    $groups = ['earning' => [], 'reimbursement' => [], 'benefit' => [], 'deduction' => [], 'contribution' => []];
    foreach ($lines as $li) {
        $type = strtolower((string)($li['type'] ?? 'earning'));
        if (!isset($groups[$type])) $type = 'earning';
        $qty = (float)($li['qty'] ?? 1);
        $label = (string)($li['description'] ?: ($li['item_name'] ?? ''));
        $detail = '';
        if (abs($qty - 1) > 0.0001 && $qty > 0) {
            $detail = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') . ' × ' . $money(abs((int)$li['rate_cents']));
        }
        // deductions are stored negative: shown as positive amounts taken off (a refund shows negative)
        $groups[$type][] = ['name' => $label, 'detail' => $detail, 'amt' => $type === 'deduction' ? -(int)$li['amount_cents'] : (int)$li['amount_cents']];
    }

    $gross = (int)($emp['gross_cents'] ?? 0);
    $paye = (int)($emp['paye_cents'] ?? 0);
    $uifEmp = (int)($emp['uif_employee_cents'] ?? 0);
    $uifEmpr = (int)($emp['uif_employer_cents'] ?? 0);
    $sdl = (int)($emp['sdl_cents'] ?? 0);
    $otherDed = (int)($emp['other_deductions_cents'] ?? 0);
    $reimb = (int)($emp['reimbursements_cents'] ?? 0);
    $net = (int)($emp['net_cents'] ?? 0);
    $cost = (int)($emp['employer_cost_cents'] ?? 0);
    $taxable = (int)($emp['taxable_income_cents'] ?? 0);
    $ty = (int)date('Y', strtotime($taxYearStart));
    $taxYearLabel = $ty . '/' . substr((string)($ty + 1), 2);

    $pdf = new PayslipPdf();
    $L = PayslipPdf::MARGIN;
    $R = PayslipPdf::PAGE_W - PayslipPdf::MARGIN;
    $W = $R - $L;

    // ---------- Header band ----------
    $companyLines = array_values(array_filter([
        $company['address_line1'] ?? '', $company['address_line2'] ?? '',
        trim(($company['city'] ?? '') . ' ' . ($company['postal'] ?? '')),
        !empty($company['phone']) ? 'Tel: ' . $company['phone'] : '', $company['email'] ?? '',
    ], fn($l) => trim((string)$l) !== ''));
    $refs = array_values(array_filter([
        !empty($company['reg_number']) ? 'Reg: ' . $company['reg_number'] : '',
        !empty($company['paye_reference']) ? 'PAYE ref: ' . $company['paye_reference'] : '',
    ]));
    $headerH = max(112, 52 + count($companyLines) * 12 + ($refs ? 16 : 0) + 8);
    $pdf->fillRect(0, 0, PayslipPdf::PAGE_W, $headerH, $primary);
    $pdf->textRight($R, 28, 'PAYSLIP', 22, true, $onP);
    $pdf->textRight($R, 56, (string)($run['name'] ?? ''), 10, true, $onP);
    $pdf->textRight($R, 71, $d($run['period_start']) . ' – ' . $d($run['period_end']), 9, false, $onP);
    $pdf->textRight($R, 85, 'Paid ' . $d($run['pay_date']) . '  ·  Run ' . ($run['run_number'] ?? ''), 9, false, $onP);
    $pdf->text($L, 28, $cName, 17, true, $onP);
    $y = 52;
    foreach ($companyLines as $line) { $pdf->text($L, $y, $line, 8.5, false, $onP); $y += 12; }
    if ($refs) $pdf->text($L, $y + 2, implode('   |   ', $refs), 8, false, $onP);
    $y = $headerH;
    if ($draft) {
        $pdf->fillRect(0, $y, PayslipPdf::PAGE_W, 20, '#fde68a');
        $pdf->textCenter(PayslipPdf::PAGE_W / 2, $y + 5, 'DRAFT — this pay run is not final yet. Figures may still change.', 9, true, '#78350f');
        $y += 20;
    }
    $pdf->setY($y + 22);

    // ---------- Employee details ----------
    $kv = [
        ['EMPLOYEE', $empName ?: '—'], ['NUMBER', $emp['employee_no'] ?? '—'], ['JOB TITLE', ($emp['job_title'] ?? '') ?: '—'], ['TAX NUMBER', ($emp['tax_number'] ?? '') ?: '—'],
        ['ID / PASSPORT', ($emp['id_number'] ?? '') ?: '—'], ['STARTED', $d($emp['hire_date'] ?? null)], ['PAID', ucfirst(['monthly' => 'monthly', 'fortnight' => 'every two weeks', 'weekly' => 'weekly'][$run['frequency']] ?? '')], ['TAX YEAR', $taxYearLabel],
    ];
    $startY = $pdf->getY();
    $colW = $W / 4;
    foreach ($kv as $i => [$k, $v]) {
        $x = $L + ($i % 4) * $colW;
        $ry = $startY + intdiv($i, 4) * 34;
        $pdf->text($x, $ry, $k, 7, true, $muted);
        $pdf->text($x, $ry + 11, mb_strimwidth((string)$v, 0, 26, '…'), 10.5, true, '#111827');
    }
    $pdf->setY($startY + 2 * 34 + 8);

    // ---------- Earnings | Deductions ----------
    $gap = 16;
    $cw = ($W - $gap) / 2;
    $ex = $L;
    $dx = $L + $cw + $gap;
    $sy = $pdf->getY();
    $pdf->text($ex, $sy, 'EARNINGS', 9.5, true, $primary);
    $pdf->text($dx, $sy, 'DEDUCTIONS', 9.5, true, $primary);
    $hy = $sy + 18;
    foreach ([$ex, $dx] as $x) {
        $pdf->text($x, $hy, 'DESCRIPTION', 7, true, $muted);
        $pdf->textRight($x + $cw, $hy, 'AMOUNT', 7, true, $muted);
        $pdf->line($x, $hy + 6, $x + $cw, $hy + 6, $rule, 0.6);
    }
    $rowH = 16;
    $put = function (float $x, float &$yy, string $name, string $detail, int $amt, bool $bold = false) use ($pdf, $cw, $money, $ink, $muted, $rule, $rowH) {
        $pdf->text($x, $yy, mb_strimwidth($name, 0, $detail !== '' ? 26 : 44, '…'), 9.5, $bold, $ink);
        if ($detail !== '') $pdf->textRight($x + $cw - 78, $yy + 1, $detail, 7.5, false, $muted);
        $pdf->textRight($x + $cw, $yy, $money($amt), 9.5, $bold, $ink);
        $yy += $rowH;
        $pdf->line($x, $yy - 4, $x + $cw, $yy - 4, $rule, 0.4);
    };
    $ey = $hy + 14;
    foreach ($groups['earning'] as $r) $put($ex, $ey, $r['name'], $r['detail'], $r['amt']);
    foreach ($groups['reimbursement'] as $r) $put($ex, $ey, $r['name'] . ' (refund)', $r['detail'], $r['amt']);
    $pdf->line($ex, $ey - 2, $ex + $cw, $ey - 2, '#cbd5e1', 0.8);
    $ey += 4;
    $put($ex, $ey, 'Total earnings', '', $gross + $reimb, true);

    $dy = $hy + 14;
    if ($paye > 0) $put($dx, $dy, 'Income tax (PAYE)', '', $paye);
    if ($uifEmp > 0) $put($dx, $dy, 'UIF (1%)', '', $uifEmp);
    foreach ($groups['deduction'] as $r) $put($dx, $dy, $r['name'], $r['detail'], $r['amt']);
    if (!$groups['deduction'] && $otherDed > 0) $put($dx, $dy, 'Other deductions', '', $otherDed);
    if ($paye === 0 && $uifEmp === 0 && !$groups['deduction'] && $otherDed === 0) $put($dx, $dy, 'No deductions', '', 0);
    $pdf->line($dx, $dy - 2, $dx + $cw, $dy - 2, '#cbd5e1', 0.8);
    $dy += 4;
    $put($dx, $dy, 'Total deductions', '', $paye + $uifEmp + $otherDed, true);
    $pdf->setY(max($ey, $dy) + 8);

    // ---------- Net pay ----------
    $ny = $pdf->getY();
    $pdf->fillRect($L, $ny, $W, 44, $primary);
    $pdf->text($L + 18, $ny + 17, 'NET PAY', 11, true, $onP);
    $bank = trim((string)($emp['bank_name'] ?? ''));
    $acct = preg_replace('/\D/', '', (string)($emp['bank_account_no'] ?? ''));
    if ($acct !== '') $pdf->text($L + 100, $ny + 18, 'Into ' . ($bank !== '' ? $bank . ' ' : '') . 'account ending ' . substr($acct, -4), 8.5, false, $onP);
    $pdf->textRight($R - 18, $ny + 13, $money($net), 22, true, $onP);
    $pdf->setY($ny + 60);

    // ---------- Not paid in cash: fringe benefits and employer contributions ----------
    if ($groups['benefit'] || $groups['contribution'] || $uifEmpr || $sdl) {
        $by = $pdf->getY();
        $pdf->text($L, $by, 'PAID BY YOUR EMPLOYER', 9.5, true, $primary);
        $pdf->textRight($R, $by + 1, '(not taken off your pay)', 8, false, '#9ca3af');
        $pdf->line($L, $by + 10, $R, $by + 10, $rule, 0.6);
        $yy = $by + 18;
        $full = function (string $name, int $amt, string $note = '') use ($pdf, $L, $R, &$yy, $money, $ink, $muted, $rule) {
            $pdf->text($L, $yy, $name, 9.5, false, $ink);
            if ($note !== '') $pdf->text($L + 230, $yy + 1, $note, 7.5, false, $muted);
            $pdf->textRight($R, $yy, $money($amt), 9.5, false, $ink);
            $yy += 16;
            $pdf->line($L, $yy - 4, $R, $yy - 4, $rule, 0.4);
        };
        foreach ($groups['benefit'] as $r) $full($r['name'], $r['amt'], 'taxable fringe benefit');
        foreach ($groups['contribution'] as $r) $full($r['name'], $r['amt'], 'employer contribution');
        if ($uifEmpr) $full('UIF (employer 1%)', $uifEmpr);
        if ($sdl) $full('Skills Development Levy (1%)', $sdl);
        $yy += 4;
        $pdf->text($L, $yy, 'Total cost to company', 10, true, '#111827');
        $pdf->textRight($R, $yy, $money($cost), 10, true, '#111827');
        $pdf->setY($yy + 26);
    }

    // ---------- Year to date ----------
    $ty0 = $pdf->getY();
    $pdf->text($L, $ty0, 'TAX YEAR TO DATE (' . $taxYearLabel . ($draft ? ', finalised runs only' : '') . ')', 9.5, true, $primary);
    $hy = $ty0 + 18;
    $cols = [['Earnings', 'gross'], ['Taxable income', 'taxable'], ['PAYE', 'paye'], ['UIF', 'uif_emp'], ['Net pay', 'net']];
    $cwy = $W / count($cols);
    foreach ($cols as $i => [$label, $k]) {
        $x = $L + $i * $cwy;
        $pdf->text($x, $hy, strtoupper($label), 7, true, $muted);
        $pdf->text($x, $hy + 11, $money((int)($ytd[$k] ?? 0)), 10.5, true, '#111827');
    }
    $pdf->text($L, $hy + 34, 'Taxable income this period: ' . $money($taxable), 8, false, $muted);

    $footerY = max($hy + 60, PayslipPdf::PAGE_H - 30);
    $pdf->textCenter(PayslipPdf::PAGE_W / 2, $footerY, 'Computer-generated payslip — valid without a signature. Created ' . date('j M Y H:i') . '.', 7.5, false, '#9ca3af');
    return $pdf->output();
}
