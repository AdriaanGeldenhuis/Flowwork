<?php
// finances/lib/VatAuditFile.php
//
// SARS VAT audit file: one row per contributing transaction line for a VAT
// period, on either accounting basis. The rows FOOT to the VAT201 produced by
// VatCalculator::vat201Boxes for the same period/basis:
//
//   sum(vat_cents where section = 'output') == box5_total_output_cents
//   sum(vat_cents where section = 'input')  == box9_total_input_cents
//
// Invoice (accrual) basis: every posted journal line in the period on the VAT
// output account, the VAT input account, or a revenue account.
//
// Payments (cash) basis: mirrors VatCalculator::calculatePaymentsBasis line
// for line — receipt/payment allocations apportioned over the source
// document's per-tax-code profile, credit notes / vendor credits in full at
// issue date (s21), bank-matched journal lines at transaction date, plus
// manual VAT201 adjustment journals (module 'vat_adjust').
//
// Shared by finances/export/vat_audit_file.php (CSV endpoint) and the test
// suite so the streamed file and the asserted maths are the same code.

require_once __DIR__ . '/VatCalculator.php';

class VatAuditFile
{
    /**
     * Audit rows for a period. Each row carries:
     *   entry_date, journal_id, reference, source_type, description,
     *   account_code, tax_code, rate_percent, is_capital_goods,
     *   debit, credit           (invoice basis; null on payments basis),
     *   allocation              (payments basis; null on invoice basis),
     *   net_cents               (apportioned/base net effect in cents),
     *   vat_cents               (VAT contribution in cents, signed),
     *   section                 ('output' | 'input' | 'revenue')
     */
    public static function rows(
        PDO $db,
        int $companyId,
        string $startDate,
        string $endDate,
        string $vatOutputCode,
        string $vatInputCode,
        string $basis = 'invoice'
    ): array {
        return $basis === 'payments'
            ? self::paymentsBasisRows($db, $companyId, $startDate, $endDate, $vatOutputCode, $vatInputCode)
            : self::invoiceBasisRows($db, $companyId, $startDate, $endDate, $vatOutputCode, $vatInputCode);
    }

    /** Foot the rows: ['output_vat_cents' => int, 'input_vat_cents' => int]. */
    public static function totals(array $rows): array
    {
        $out = 0;
        $in = 0;
        foreach ($rows as $r) {
            if ($r['section'] === 'output') {
                $out += (int)$r['vat_cents'];
            } elseif ($r['section'] === 'input') {
                $in += (int)$r['vat_cents'];
            }
        }
        return ['output_vat_cents' => $out, 'input_vat_cents' => $in];
    }

    // ------------------------------------------------------------------
    // Invoice (accrual) basis
    // ------------------------------------------------------------------

    public static function invoiceBasisRows(
        PDO $db,
        int $companyId,
        string $startDate,
        string $endDate,
        string $vatOutputCode,
        string $vatInputCode
    ): array {
        $stmt = $db->prepare(
            "SELECT je.entry_date, je.id AS journal_id, je.reference,
                    COALESCE(je.source_type, je.ref_type, je.module) AS source_type,
                    COALESCE(jl.description, je.description) AS description,
                    jl.account_code, tc.code AS tax_code, tc.rate_percent,
                    jl.is_capital_goods, jl.debit, jl.credit,
                    ROUND(jl.debit * 100)  AS debit_cents,
                    ROUND(jl.credit * 100) AS credit_cents,
                    ga.account_type
               FROM journal_lines jl
               JOIN journal_entries je ON je.id = jl.journal_id
               JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id
               LEFT JOIN gl_tax_codes tc ON tc.tax_code_id = jl.tax_code_id
              WHERE je.company_id = ? AND je.status = 'posted'
                AND je.entry_date BETWEEN ? AND ?
                AND COALESCE(je.module,'') <> 'vat_settle'
                AND (jl.account_code IN (?, ?) OR ga.account_type = 'revenue')
              ORDER BY je.entry_date, je.id, jl.id"
        );
        $stmt->execute([$companyId, $startDate, $endDate, $vatOutputCode, $vatInputCode]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $debitC = (int)$r['debit_cents'];
            $creditC = (int)$r['credit_cents'];
            if ($r['account_code'] === $vatOutputCode) {
                $section = 'output';
                $netC = $creditC - $debitC; // output/revenue: credit-positive
                $vatC = $netC;
            } elseif ($r['account_code'] === $vatInputCode) {
                $section = 'input';
                $netC = $debitC - $creditC; // input: debit-positive
                $vatC = $netC;
            } else {
                $section = 'revenue';
                $netC = $creditC - $debitC;
                $vatC = 0;
            }
            $rows[] = [
                'entry_date'       => $r['entry_date'],
                'journal_id'       => (int)$r['journal_id'],
                'reference'        => $r['reference'],
                'source_type'      => $r['source_type'],
                'description'      => $r['description'],
                'account_code'     => $r['account_code'],
                'tax_code'         => $r['tax_code'],
                'rate_percent'     => $r['rate_percent'] !== null ? (float)$r['rate_percent'] : null,
                'is_capital_goods' => (int)$r['is_capital_goods'],
                'debit'            => (float)$r['debit'],
                'credit'           => (float)$r['credit'],
                'allocation'       => null,
                'net_cents'        => $netC,
                'vat_cents'        => $vatC,
                'section'          => $section,
            ];
        }
        return $rows;
    }

    // ------------------------------------------------------------------
    // Payments (cash) basis — mirrors VatCalculator::calculatePaymentsBasis
    // ------------------------------------------------------------------

    public static function paymentsBasisRows(
        PDO $db,
        int $companyId,
        string $startDate,
        string $endDate,
        string $vatOutputCode,
        string $vatInputCode
    ): array {
        $rows = [];

        // Per-tax-code profile of a document's lines, identical rounding to
        // VatCalculator: ['CODE' => ['base' => cents, 'vat' => cents]].
        $profile = function (array $lines): array {
            $p = [];
            foreach ($lines as $l) {
                $netC = (int)round(((float)$l['quantity'] * (float)$l['unit_price'] - (float)($l['discount'] ?? 0)) * 100);
                $rate = (float)($l['tax_rate'] ?? 0);
                $vatC = (int)round($netC * $rate / 100);
                $code = $l['tax_code'] ?? ($rate > 0.005 ? 'STD' : 'ZERO');
                $code = strtoupper($code ?: ($rate > 0.005 ? 'STD' : 'ZERO'));
                if (!isset($p[$code])) {
                    $p[$code] = ['base' => 0, 'vat' => 0];
                }
                $p[$code]['base'] += $netC;
                $p[$code]['vat'] += $vatC;
            }
            return $p;
        };
        // VatCalculator::accumulateOutput only counts VAT for rated codes.
        $countedVat = fn(string $code, int $vatC): int =>
            in_array($code, ['ZERO', 'EXEMPT', 'UNTAGGED'], true) ? 0 : $vatC;

        // ---- OUTPUT: customer receipts apportioned over invoice profiles ----
        // RUNNING-TOTAL rounding per (invoice, tax code), identical to
        // VatCalculator: each allocation's row carries the DELTA of
        // round(codeTotal × cumulativeFraction × fx), so the rows telescope
        // to the invoice's exact VAT at full settlement.
        $invoiceInfo = [];
        $loadInvoice = function (int $invId) use ($db, $profile, &$invoiceInfo): array {
            if (!isset($invoiceInfo[$invId])) {
                $hdr = $db->prepare("SELECT invoice_number, total, exchange_rate, currency FROM invoices WHERE id = ?");
                $hdr->execute([$invId]);
                $h = $hdr->fetch(PDO::FETCH_ASSOC) ?: ['invoice_number' => '?', 'total' => 0, 'exchange_rate' => 1, 'currency' => 'ZAR'];
                $ls = $db->prepare(
                    "SELECT il.quantity, il.unit_price, il.discount, il.tax_rate, tc.code AS tax_code
                       FROM invoice_lines il
                       LEFT JOIN gl_tax_codes tc ON tc.tax_code_id = il.tax_code_id
                      WHERE il.invoice_id = ?"
                );
                $ls->execute([$invId]);
                $fx = (strtoupper($h['currency'] ?? 'ZAR') === 'ZAR' || (float)$h['exchange_rate'] <= 0)
                    ? 1.0 : (float)$h['exchange_rate'];
                $invoiceInfo[$invId] = [
                    'number'  => $h['invoice_number'],
                    'total'   => (float)$h['total'],
                    'fx'      => $fx,
                    'profile' => $profile($ls->fetchAll(PDO::FETCH_ASSOC)),
                ];
            }
            return $invoiceInfo[$invId];
        };
        $fractionUpTo = function (int $invId, string $date) use ($db, $loadInvoice): float {
            $inv = $loadInvoice($invId);
            if ($inv['total'] == 0.0) {
                return 0.0;
            }
            $s = $db->prepare(
                "SELECT COALESCE(SUM(pa.amount),0) FROM payment_allocations pa
                   JOIN payments p ON p.id = pa.payment_id
                  WHERE pa.invoice_id = ? AND p.payment_date <= ?"
            );
            $s->execute([$invId, $date]);
            return ((float)$s->fetchColumn()) / $inv['total'];
        };

        $stmt = $db->prepare(
            "SELECT DISTINCT pa.invoice_id
               FROM payment_allocations pa
               JOIN payments p ON p.id = pa.payment_id
              WHERE p.company_id = ? AND p.payment_date BETWEEN ? AND ?"
        );
        $stmt->execute([$companyId, $startDate, $endDate]);
        foreach (array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) as $invId) {
            $inv = $loadInvoice($invId);
            if ($inv['total'] == 0.0) {
                continue;
            }
            $als = $db->prepare(
                "SELECT pa.amount AS alloc, p.payment_date, p.journal_id, p.reference AS pay_ref
                   FROM payment_allocations pa
                   JOIN payments p ON p.id = pa.payment_id
                  WHERE pa.invoice_id = ? AND p.payment_date <= ?
                  ORDER BY p.payment_date, p.id, pa.id"
            );
            $als->execute([$invId, $endDate]);
            $cumAlloc = 0.0;
            $prev = [];
            foreach ($als->fetchAll(PDO::FETCH_ASSOC) as $al) {
                $cumAlloc += (float)$al['alloc'];
                $frac = $cumAlloc / $inv['total'];
                foreach ($inv['profile'] as $code => $amounts) {
                    $newBase = (int)round($amounts['base'] * $frac * $inv['fx']);
                    $newVat  = (int)round($amounts['vat'] * $frac * $inv['fx']);
                    $dBase = $newBase - ($prev[$code]['base'] ?? 0);
                    $dVat  = $newVat - ($prev[$code]['vat'] ?? 0);
                    $prev[$code] = ['base' => $newBase, 'vat' => $newVat];
                    if ($al['payment_date'] < $startDate || ($dBase === 0 && $dVat === 0)) {
                        continue;
                    }
                    $rows[] = [
                        'entry_date'       => $al['payment_date'],
                        'journal_id'       => $al['journal_id'] !== null ? (int)$al['journal_id'] : null,
                        'reference'        => $inv['number'],
                        'source_type'      => 'payment_allocation',
                        'description'      => 'Receipt ' . ($al['pay_ref'] ?: '') . ' allocated to ' . $inv['number'],
                        'account_code'     => $vatOutputCode,
                        'tax_code'         => $code,
                        'rate_percent'     => null,
                        'is_capital_goods' => 0,
                        'debit'            => null,
                        'credit'           => null,
                        'allocation'       => (float)$al['alloc'],
                        'net_cents'        => $dBase,
                        'vat_cents'        => $countedVat($code, $dVat),
                        'section'          => 'output',
                    ];
                }
            }
        }

        // Credit notes (s21) at issue date — receipts clawback, identical to
        // VatCalculator: recognised only to the extent output tax was
        // previously accounted for on the linked invoice's receipts; a credit
        // absorbs the unpaid balance first. Standalone credit notes are
        // recognised in full.
        $stmt = $db->prepare(
            "SELECT cn.id, cn.invoice_id, cn.credit_note_number, cn.issue_date, cn.journal_id,
                    cn.exchange_rate, cn.currency
               FROM credit_notes cn
              WHERE cn.company_id = ? AND cn.issue_date BETWEEN ? AND ?
                AND cn.journal_id IS NOT NULL"
        );
        $stmt->execute([$companyId, $startDate, $endDate]);
        $cnProfileFor = function (int $cnId) use ($db, $profile): array {
            $ls = $db->prepare(
                "SELECT cl.quantity, cl.unit_price, cl.discount, cl.tax_rate, tc.code AS tax_code
                   FROM credit_note_lines cl
                   LEFT JOIN gl_tax_codes tc ON tc.tax_code_id = cl.tax_code_id
                  WHERE cl.credit_note_id = ?"
            );
            $ls->execute([$cnId]);
            return $profile($ls->fetchAll(PDO::FETCH_ASSOC));
        };
        $processedInvoiceCns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $cn) {
            $linkedInvoiceId = !empty($cn['invoice_id']) ? (int)$cn['invoice_id'] : 0;
            if (!$linkedInvoiceId) {
                $fx = (strtoupper($cn['currency'] ?? 'ZAR') === 'ZAR' || (float)$cn['exchange_rate'] <= 0)
                    ? 1.0 : (float)$cn['exchange_rate'];
                foreach ($cnProfileFor((int)$cn['id']) as $code => $amounts) {
                    $rows[] = [
                        'entry_date'       => $cn['issue_date'],
                        'journal_id'       => (int)$cn['journal_id'],
                        'reference'        => $cn['credit_note_number'],
                        'source_type'      => 'credit_note',
                        'description'      => 'Credit note ' . $cn['credit_note_number'] . ' (s21, standalone, at issue date)',
                        'account_code'     => $vatOutputCode,
                        'tax_code'         => $code,
                        'rate_percent'     => null,
                        'is_capital_goods' => 0,
                        'debit'            => null,
                        'credit'           => null,
                        'allocation'       => null,
                        'net_cents'        => -(int)round($amounts['base'] * $fx),
                        'vat_cents'        => $countedVat($code, -(int)round($amounts['vat'] * $fx)),
                        'section'          => 'output',
                    ];
                }
                continue;
            }
            if (isset($processedInvoiceCns[$linkedInvoiceId])) {
                continue; // whole linked-invoice CN chain handled in one replay
            }
            $processedInvoiceCns[$linkedInvoiceId] = true;
            $inv = $loadInvoice($linkedInvoiceId);
            $fx = $inv['fx'];
            $invZar = [];
            foreach ($inv['profile'] as $code => $amounts) {
                $invZar[$code] = [
                    'base' => (int)round($amounts['base'] * $fx),
                    'vat'  => (int)round($amounts['vat'] * $fx),
                ];
            }
            $chain = $db->prepare(
                "SELECT id, credit_note_number, issue_date, journal_id FROM credit_notes
                  WHERE invoice_id = ? AND journal_id IS NOT NULL AND issue_date <= ?
                  ORDER BY issue_date, id"
            );
            $chain->execute([$linkedInvoiceId, $endDate]);
            $cumRaw = [];
            $prevRec = [];
            foreach ($chain->fetchAll(PDO::FETCH_ASSOC) as $link) {
                foreach ($cnProfileFor((int)$link['id']) as $code => $amounts) {
                    $cumRaw[$code]['base'] = ($cumRaw[$code]['base'] ?? 0) + (int)round($amounts['base'] * $fx);
                    $cumRaw[$code]['vat']  = ($cumRaw[$code]['vat'] ?? 0) + (int)round($amounts['vat'] * $fx);
                }
                $fracAtIssue = $fractionUpTo($linkedInvoiceId, $link['issue_date']);
                foreach ($cumRaw as $code => $raw) {
                    $invBase = $invZar[$code]['base'] ?? 0;
                    $invVat  = $invZar[$code]['vat'] ?? 0;
                    $recBase = (int)round($invBase * $fracAtIssue);
                    $recVat  = (int)round($invVat * $fracAtIssue);
                    $cumRecBase = min($raw['base'], max(0, $recBase - ($invBase - $raw['base'])));
                    $cumRecVat  = min($raw['vat'], max(0, $recVat - ($invVat - $raw['vat'])));
                    $dBase = $cumRecBase - ($prevRec[$code]['base'] ?? 0);
                    $dVat  = $cumRecVat - ($prevRec[$code]['vat'] ?? 0);
                    $prevRec[$code] = ['base' => $cumRecBase, 'vat' => $cumRecVat];
                    if ($link['issue_date'] < $startDate || ($dBase === 0 && $dVat === 0)) {
                        continue;
                    }
                    $rows[] = [
                        'entry_date'       => $link['issue_date'],
                        'journal_id'       => (int)$link['journal_id'],
                        'reference'        => $link['credit_note_number'],
                        'source_type'      => 'credit_note',
                        'description'      => 'Credit note ' . $link['credit_note_number'] . ' (s21, receipts clawback)',
                        'account_code'     => $vatOutputCode,
                        'tax_code'         => $code,
                        'rate_percent'     => null,
                        'is_capital_goods' => 0,
                        'debit'            => null,
                        'credit'           => null,
                        'allocation'       => null,
                        'net_cents'        => -$dBase,
                        'vat_cents'        => $countedVat($code, -$dVat),
                        'section'          => 'output',
                    ];
                }
            }
        }

        // ---- INPUT: supplier payments apportioned over bill profiles --------
        // Same running-total rounding as the calculator (per bill, split
        // capital/other): rows telescope to the bill's exact input VAT.
        $stmt = $db->prepare(
            "SELECT DISTINCT apa.bill_id
               FROM ap_payment_allocations apa
               JOIN ap_payments p ON p.id = apa.ap_payment_id
              WHERE p.company_id = ? AND p.payment_date BETWEEN ? AND ?"
        );
        $stmt->execute([$companyId, $startDate, $endDate]);
        foreach (array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) as $billId) {
            $hdr = $db->prepare("SELECT total, vendor_invoice_number FROM ap_bills WHERE id = ?");
            $hdr->execute([$billId]);
            $bill = $hdr->fetch(PDO::FETCH_ASSOC);
            $billTotal = (float)($bill['total'] ?? 0);
            if ($billTotal == 0.0) {
                continue;
            }
            $ls = $db->prepare(
                "SELECT bl.quantity, bl.unit_price, bl.discount, bl.tax_rate,
                        (ga.account_subtype = 'fixed_asset') AS is_capital
                   FROM ap_bill_lines bl
                   LEFT JOIN gl_accounts ga ON ga.account_id = bl.gl_account_id
                  WHERE bl.bill_id = ?"
            );
            $ls->execute([$billId]);
            $capVat = 0;
            $otherVat = 0;
            $capNet = 0;
            $otherNet = 0;
            foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $netC = (int)round(((float)$l['quantity'] * (float)$l['unit_price'] - (float)($l['discount'] ?? 0)) * 100);
                $vatC = (int)round($netC * (float)($l['tax_rate'] ?? 0) / 100);
                if (!empty($l['is_capital'])) {
                    $capVat += $vatC;
                    $capNet += $netC;
                } else {
                    $otherVat += $vatC;
                    $otherNet += $netC;
                }
            }
            $als = $db->prepare(
                "SELECT apa.amount AS alloc, p.payment_date, p.journal_id, p.reference AS pay_ref
                   FROM ap_payment_allocations apa
                   JOIN ap_payments p ON p.id = apa.ap_payment_id
                  WHERE apa.bill_id = ? AND p.payment_date <= ?
                  ORDER BY p.payment_date, p.id, apa.id"
            );
            $als->execute([$billId, $endDate]);
            $cumAlloc = 0.0;
            $prevCap = ['net' => 0, 'vat' => 0];
            $prevOther = ['net' => 0, 'vat' => 0];
            foreach ($als->fetchAll(PDO::FETCH_ASSOC) as $al) {
                $cumAlloc += (float)$al['alloc'];
                $frac = $cumAlloc / $billTotal;
                foreach ([[1, $capNet, $capVat, &$prevCap], [0, $otherNet, $otherVat, &$prevOther]] as [$isCap, $netTot, $vatTot, &$prev]) {
                    $newNet = (int)round($netTot * $frac);
                    $newVat = (int)round($vatTot * $frac);
                    $dNet = $newNet - $prev['net'];
                    $dVat = $newVat - $prev['vat'];
                    $prev = ['net' => $newNet, 'vat' => $newVat];
                    if ($al['payment_date'] < $startDate || ($dNet === 0 && $dVat === 0)) {
                        continue;
                    }
                    $rows[] = [
                        'entry_date'       => $al['payment_date'],
                        'journal_id'       => $al['journal_id'] !== null ? (int)$al['journal_id'] : null,
                        'reference'        => $bill['vendor_invoice_number'],
                        'source_type'      => 'ap_payment_allocation',
                        'description'      => 'Payment ' . ($al['pay_ref'] ?: '') . ' allocated to bill ' . ($bill['vendor_invoice_number'] ?: $billId),
                        'account_code'     => $vatInputCode,
                        'tax_code'         => 'INPUT',
                        'rate_percent'     => null,
                        'is_capital_goods' => $isCap,
                        'debit'            => null,
                        'credit'           => null,
                        'allocation'       => (float)$al['alloc'],
                        'net_cents'        => $dNet,
                        'vat_cents'        => $dVat,
                        'section'          => 'input',
                    ];
                }
                unset($prev);
            }
        }

        // Vendor credits at issue date — full reversal, capital/other split
        // per line (mirrors the calculator).
        $stmt = $db->prepare(
            "SELECT vc.id, vc.credit_number, vc.issue_date, vc.journal_id
               FROM vendor_credits vc
              WHERE vc.company_id = ? AND vc.issue_date BETWEEN ? AND ?
                AND vc.journal_id IS NOT NULL"
        );
        $stmt->execute([$companyId, $startDate, $endDate]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $vc) {
            $ls = $db->prepare(
                "SELECT vcl.quantity, vcl.unit_price, vcl.discount, vcl.tax_rate,
                        (ga.account_subtype = 'fixed_asset') AS is_capital
                   FROM vendor_credit_lines vcl
                   LEFT JOIN gl_accounts ga ON ga.account_id = vcl.gl_account_id AND ga.company_id = ?
                  WHERE vcl.credit_id = ?"
            );
            $ls->execute([$companyId, (int)$vc['id']]);
            $split = [1 => ['net' => 0, 'vat' => 0], 0 => ['net' => 0, 'vat' => 0]];
            foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $netC = (int)round(((float)$l['quantity'] * (float)$l['unit_price'] - (float)($l['discount'] ?? 0)) * 100);
                $vatC = (int)round($netC * (float)($l['tax_rate'] ?? 0) / 100);
                $k = !empty($l['is_capital']) ? 1 : 0;
                $split[$k]['net'] += $netC;
                $split[$k]['vat'] += $vatC;
            }
            foreach ($split as $isCap => $tot) {
                if ($tot['net'] === 0 && $tot['vat'] === 0) {
                    continue;
                }
                $rows[] = [
                    'entry_date'       => $vc['issue_date'],
                    'journal_id'       => (int)$vc['journal_id'],
                    'reference'        => $vc['credit_number'],
                    'source_type'      => 'vendor_credit',
                    'description'      => 'Vendor credit ' . $vc['credit_number'] . ' (s21, at issue date)',
                    'account_code'     => $vatInputCode,
                    'tax_code'         => 'INPUT',
                    'rate_percent'     => null,
                    'is_capital_goods' => $isCap,
                    'debit'            => null,
                    'credit'           => null,
                    'allocation'       => null,
                    'net_cents'        => -$tot['net'],
                    'vat_cents'        => -$tot['vat'],
                    'section'          => 'input',
                ];
            }
        }

        // ---- Bank-matched items: inherently cash-dated ----------------------
        // Revenue lines on bank journals feed the output BASE figures.
        $stmt = $db->prepare(
            "SELECT je.entry_date, je.id AS journal_id, je.reference,
                    COALESCE(jl.description, je.description) AS description,
                    jl.account_code, COALESCE(tc.code, 'UNTAGGED') AS tax_code, tc.rate_percent,
                    ROUND(jl.credit * 100) - ROUND(jl.debit * 100) AS credit_net_cents
               FROM journal_lines jl
               JOIN journal_entries je ON je.id = jl.journal_id
               JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id
               LEFT JOIN gl_tax_codes tc ON tc.tax_code_id = jl.tax_code_id
              WHERE je.company_id = ? AND je.status = 'posted'
                AND je.ref_type = 'bank_tx'
                AND je.entry_date BETWEEN ? AND ?
                AND ga.account_type = 'revenue'
              ORDER BY je.entry_date, je.id, jl.id"
        );
        $stmt->execute([$companyId, $startDate, $endDate]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'entry_date'       => $r['entry_date'],
                'journal_id'       => (int)$r['journal_id'],
                'reference'        => $r['reference'],
                'source_type'      => 'bank_tx',
                'description'      => $r['description'],
                'account_code'     => $r['account_code'],
                'tax_code'         => strtoupper($r['tax_code']) ?: 'UNTAGGED',
                'rate_percent'     => $r['rate_percent'] !== null ? (float)$r['rate_percent'] : null,
                'is_capital_goods' => 0,
                'debit'            => null,
                'credit'           => null,
                'allocation'       => null,
                'net_cents'        => (int)$r['credit_net_cents'],
                'vat_cents'        => 0, // base only; the VAT sits on the VAT leg below
                'section'          => 'output',
            ];
        }

        // Bank VAT legs: output VAT credited / input VAT debited.
        $stmt = $db->prepare(
            "SELECT je.entry_date, je.id AS journal_id, je.reference,
                    COALESCE(jl.description, je.description) AS description,
                    jl.account_code, jl.is_capital_goods, tc.code AS tax_code, tc.rate_percent,
                    ROUND(jl.credit * 100) - ROUND(jl.debit * 100) AS credit_net_cents,
                    ROUND(jl.debit * 100) - ROUND(jl.credit * 100) AS debit_net_cents
               FROM journal_lines jl
               JOIN journal_entries je ON je.id = jl.journal_id
               LEFT JOIN gl_tax_codes tc ON tc.tax_code_id = jl.tax_code_id
              WHERE je.company_id = ? AND je.status = 'posted'
                AND je.ref_type = 'bank_tx'
                AND je.entry_date BETWEEN ? AND ?
                AND jl.account_code IN (?, ?)
              ORDER BY je.entry_date, je.id, jl.id"
        );
        $stmt->execute([$companyId, $startDate, $endDate, $vatOutputCode, $vatInputCode]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $isOutput = $r['account_code'] === $vatOutputCode;
            $rows[] = [
                'entry_date'       => $r['entry_date'],
                'journal_id'       => (int)$r['journal_id'],
                'reference'        => $r['reference'],
                'source_type'      => 'bank_tx',
                'description'      => $r['description'],
                'account_code'     => $r['account_code'],
                'tax_code'         => $r['tax_code'],
                'rate_percent'     => $r['rate_percent'] !== null ? (float)$r['rate_percent'] : null,
                'is_capital_goods' => (int)$r['is_capital_goods'],
                'debit'            => null,
                'credit'           => null,
                'allocation'       => null,
                'net_cents'        => 0,
                'vat_cents'        => $isOutput ? (int)$r['credit_net_cents'] : (int)$r['debit_net_cents'],
                'section'          => $isOutput ? 'output' : 'input',
            ];
        }

        // Manual VAT201 adjustments (Box 4 / Box 6A) — vat201Boxes adds these
        // on top of the payments-basis aggregates, so the file must carry them
        // to foot to Box 5 / Box 9.
        $stmt = $db->prepare(
            "SELECT je.entry_date, je.id AS journal_id, je.reference,
                    COALESCE(jl.description, je.description) AS description,
                    jl.account_code, jl.is_capital_goods, tc.code AS tax_code, tc.rate_percent,
                    ROUND(jl.credit * 100) - ROUND(jl.debit * 100) AS credit_net_cents,
                    ROUND(jl.debit * 100) - ROUND(jl.credit * 100) AS debit_net_cents
               FROM journal_lines jl
               JOIN journal_entries je ON je.id = jl.journal_id
               LEFT JOIN gl_tax_codes tc ON tc.tax_code_id = jl.tax_code_id
              WHERE je.company_id = ? AND je.status = 'posted'
                AND je.module = 'vat_adjust'
                AND je.entry_date BETWEEN ? AND ?
                AND jl.account_code IN (?, ?)
              ORDER BY je.entry_date, je.id, jl.id"
        );
        $stmt->execute([$companyId, $startDate, $endDate, $vatOutputCode, $vatInputCode]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $isOutput = $r['account_code'] === $vatOutputCode;
            $rows[] = [
                'entry_date'       => $r['entry_date'],
                'journal_id'       => (int)$r['journal_id'],
                'reference'        => $r['reference'],
                'source_type'      => 'vat_adjust',
                'description'      => $r['description'],
                'account_code'     => $r['account_code'],
                'tax_code'         => $r['tax_code'],
                'rate_percent'     => $r['rate_percent'] !== null ? (float)$r['rate_percent'] : null,
                'is_capital_goods' => (int)$r['is_capital_goods'],
                'debit'            => null,
                'credit'           => null,
                'allocation'       => null,
                'net_cents'        => 0,
                'vat_cents'        => $isOutput ? (int)$r['credit_net_cents'] : (int)$r['debit_net_cents'],
                'section'          => $isOutput ? 'output' : 'input',
            ];
        }

        return $rows;
    }
}
