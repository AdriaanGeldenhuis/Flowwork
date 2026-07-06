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
        $stmt = $db->prepare(
            "SELECT pa.amount AS alloc, p.payment_date, p.journal_id, p.reference AS pay_ref,
                    i.id AS invoice_id, i.invoice_number, i.total, i.exchange_rate, i.currency
               FROM payment_allocations pa
               JOIN payments p ON p.id = pa.payment_id
               JOIN invoices i ON i.id = pa.invoice_id
              WHERE p.company_id = ? AND p.payment_date BETWEEN ? AND ?"
        );
        $stmt->execute([$companyId, $startDate, $endDate]);
        $invoiceProfiles = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $al) {
            $invId = (int)$al['invoice_id'];
            if (!isset($invoiceProfiles[$invId])) {
                $ls = $db->prepare(
                    "SELECT il.quantity, il.unit_price, il.discount, il.tax_rate, tc.code AS tax_code
                       FROM invoice_lines il
                       LEFT JOIN gl_tax_codes tc ON tc.tax_code_id = il.tax_code_id
                      WHERE il.invoice_id = ?"
                );
                $ls->execute([$invId]);
                $invoiceProfiles[$invId] = $profile($ls->fetchAll(PDO::FETCH_ASSOC));
            }
            $total = (float)$al['total'];
            if ($total == 0.0) {
                continue;
            }
            $fraction = (float)$al['alloc'] / $total;
            $fx = (strtoupper($al['currency'] ?? 'ZAR') === 'ZAR' || (float)$al['exchange_rate'] <= 0)
                ? 1.0 : (float)$al['exchange_rate'];
            foreach ($invoiceProfiles[$invId] as $code => $amounts) {
                $baseC = (int)round($amounts['base'] * $fraction * $fx);
                $vatC  = (int)round($amounts['vat'] * $fraction * $fx);
                $rows[] = [
                    'entry_date'       => $al['payment_date'],
                    'journal_id'       => $al['journal_id'] !== null ? (int)$al['journal_id'] : null,
                    'reference'        => $al['invoice_number'],
                    'source_type'      => 'payment_allocation',
                    'description'      => 'Receipt ' . ($al['pay_ref'] ?: '') . ' allocated to ' . $al['invoice_number'],
                    'account_code'     => $vatOutputCode,
                    'tax_code'         => $code,
                    'rate_percent'     => null,
                    'is_capital_goods' => 0,
                    'debit'            => null,
                    'credit'           => null,
                    'allocation'       => (float)$al['alloc'],
                    'net_cents'        => $baseC,
                    'vat_cents'        => $countedVat($code, $vatC),
                    'section'          => 'output',
                ];
            }
        }

        // Credit notes at issue date (s21) — full reversal of the credit.
        $stmt = $db->prepare(
            "SELECT cn.id, cn.credit_note_number, cn.issue_date, cn.journal_id, cn.exchange_rate, cn.currency
               FROM credit_notes cn
              WHERE cn.company_id = ? AND cn.issue_date BETWEEN ? AND ?
                AND cn.journal_id IS NOT NULL"
        );
        $stmt->execute([$companyId, $startDate, $endDate]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $cn) {
            $ls = $db->prepare(
                "SELECT cl.quantity, cl.unit_price, cl.discount, cl.tax_rate, tc.code AS tax_code
                   FROM credit_note_lines cl
                   LEFT JOIN gl_tax_codes tc ON tc.tax_code_id = cl.tax_code_id
                  WHERE cl.credit_note_id = ?"
            );
            $ls->execute([(int)$cn['id']]);
            $fx = (strtoupper($cn['currency'] ?? 'ZAR') === 'ZAR' || (float)$cn['exchange_rate'] <= 0)
                ? 1.0 : (float)$cn['exchange_rate'];
            foreach ($profile($ls->fetchAll(PDO::FETCH_ASSOC)) as $code => $amounts) {
                $rows[] = [
                    'entry_date'       => $cn['issue_date'],
                    'journal_id'       => (int)$cn['journal_id'],
                    'reference'        => $cn['credit_note_number'],
                    'source_type'      => 'credit_note',
                    'description'      => 'Credit note ' . $cn['credit_note_number'] . ' (s21, at issue date)',
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
        }

        // ---- INPUT: supplier payments apportioned over bill profiles --------
        $stmt = $db->prepare(
            "SELECT apa.amount AS alloc, p.payment_date, p.journal_id, p.reference AS pay_ref,
                    b.id AS bill_id, b.total, b.vendor_invoice_number
               FROM ap_payment_allocations apa
               JOIN ap_payments p ON p.id = apa.ap_payment_id
               JOIN ap_bills b ON b.id = apa.bill_id
              WHERE p.company_id = ? AND p.payment_date BETWEEN ? AND ?"
        );
        $stmt->execute([$companyId, $startDate, $endDate]);
        $billProfiles = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $al) {
            $billId = (int)$al['bill_id'];
            if (!isset($billProfiles[$billId])) {
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
                $billProfiles[$billId] = [
                    'capital' => $capVat, 'other' => $otherVat,
                    'capital_net' => $capNet, 'other_net' => $otherNet,
                ];
            }
            $total = (float)$al['total'];
            if ($total == 0.0) {
                continue;
            }
            $fraction = (float)$al['alloc'] / $total;
            $p = $billProfiles[$billId];
            foreach ([['capital', 'capital_net', 1], ['other', 'other_net', 0]] as [$vatKey, $netKey, $isCap]) {
                if ($p[$vatKey] === 0 && $p[$netKey] === 0) {
                    continue;
                }
                $rows[] = [
                    'entry_date'       => $al['payment_date'],
                    'journal_id'       => $al['journal_id'] !== null ? (int)$al['journal_id'] : null,
                    'reference'        => $al['vendor_invoice_number'],
                    'source_type'      => 'ap_payment_allocation',
                    'description'      => 'Payment ' . ($al['pay_ref'] ?: '') . ' allocated to bill ' . ($al['vendor_invoice_number'] ?: $billId),
                    'account_code'     => $vatInputCode,
                    'tax_code'         => 'INPUT',
                    'rate_percent'     => null,
                    'is_capital_goods' => $isCap,
                    'debit'            => null,
                    'credit'           => null,
                    'allocation'       => (float)$al['alloc'],
                    'net_cents'        => (int)round($p[$netKey] * $fraction),
                    'vat_cents'        => (int)round($p[$vatKey] * $fraction),
                    'section'          => 'input',
                ];
            }
        }

        // Vendor credits at issue date — full reversal.
        $stmt = $db->prepare(
            "SELECT vc.id, vc.credit_number, vc.issue_date, vc.journal_id
               FROM vendor_credits vc
              WHERE vc.company_id = ? AND vc.issue_date BETWEEN ? AND ?
                AND vc.journal_id IS NOT NULL"
        );
        $stmt->execute([$companyId, $startDate, $endDate]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $vc) {
            $ls = $db->prepare(
                "SELECT quantity, unit_price, discount, tax_rate FROM vendor_credit_lines WHERE credit_id = ?"
            );
            $ls->execute([(int)$vc['id']]);
            $netTotC = 0;
            $vatTotC = 0;
            foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $netC = (int)round(((float)$l['quantity'] * (float)$l['unit_price'] - (float)($l['discount'] ?? 0)) * 100);
                $netTotC += $netC;
                $vatTotC += (int)round($netC * (float)($l['tax_rate'] ?? 0) / 100);
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
                'is_capital_goods' => 0,
                'debit'            => null,
                'credit'           => null,
                'allocation'       => null,
                'net_cents'        => -$netTotC,
                'vat_cents'        => -$vatTotC,
                'section'          => 'input',
            ];
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
