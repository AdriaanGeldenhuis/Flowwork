<?php
// finances/lib/VatCalculator.php
//
// Centralised VAT calculation for SARS VAT201 returns, from posted journal
// lines (invoice/accrual basis). Output and input tax are broken down by tax
// code so the VAT201 boxes (standard, zero-rated, exempt, capital goods) are
// populated exactly. Untagged revenue is NOT silently assumed standard-rated:
// it is reported separately so the health check can flag it.

class VatCalculator
{
    /** The company's elected VAT accounting basis: 'invoice' or 'payments'. */
    public static function companyBasis(PDO $db, int $companyId): string
    {
        $stmt = $db->prepare(
            "SELECT setting_value FROM company_settings
              WHERE company_id = ? AND setting_key = 'finance_vat_basis' LIMIT 1"
        );
        $stmt->execute([$companyId]);
        $v = strtolower(trim((string)$stmt->fetchColumn()));
        return $v === 'payments' ? 'payments' : 'invoice';
    }

    /**
     * Basis to compute a specific VAT period on. An OPEN period has no
     * snapshot yet and must follow the company's CURRENT basis election —
     * gl_vat_periods.basis is NOT NULL DEFAULT 'invoice', so reading the
     * stored value before prepare time silently showed accrual figures to a
     * payments-basis vendor. The basis is stamped at prepare time
     * (vat_save.php) and trusted from then on.
     */
    public static function periodBasis(PDO $db, int $companyId, array $period): string
    {
        if (($period['status'] ?? 'open') === 'open') {
            return self::companyBasis($db, $companyId);
        }
        return ($period['basis'] ?? '') ?: self::companyBasis($db, $companyId);
    }

    /**
     * Full SARS VAT201 box set for a period — the ONE computation every
     * consumer (report page, prepare screen, CSV export, period snapshot)
     * must share. Supplies figures exclude manual adjustment journals, which
     * are measured separately (Box 4 output / Box 6A input); Box 5/9/10 are
     * the SARS arithmetic:
     *   Box 5  = 1A + 4,  Box 9 = 6A + 7 + 8,  Box 10 = 5 - 9.
     */
    public static function vat201Boxes(
        PDO $db,
        int $companyId,
        string $startDate,
        string $endDate,
        string $vatOutputCode,
        string $vatInputCode,
        string $basis = 'invoice'
    ): array {
        $data = $basis === 'payments'
            ? self::calculatePaymentsBasis($db, $companyId, $startDate, $endDate)
            : self::calculate($db, $companyId, $startDate, $endDate,
                $vatOutputCode, $vatInputCode, true);
        $data['basis'] = $basis;

        // Manual adjustments (module 'vat_adjust') post INTO the VAT output
        // and input accounts — measure them there. Output increases are
        // credits; input increases are debits.
        $stmt = $db->prepare(
            "SELECT jl.account_code,
                    SUM(jl.credit - jl.debit) AS credit_net,
                    SUM(jl.debit - jl.credit) AS debit_net
             FROM journal_lines jl
             JOIN journal_entries je ON jl.journal_id = je.id
             WHERE je.company_id = ? AND je.status = 'posted'
               AND je.entry_date BETWEEN ? AND ?
               AND je.module = 'vat_adjust'
               AND jl.account_code IN (?, ?)
             GROUP BY jl.account_code"
        );
        $stmt->execute([$companyId, $startDate, $endDate, $vatOutputCode, $vatInputCode]);
        $adjOutputC = 0;
        $adjInputC = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['account_code'] === $vatOutputCode) {
                $adjOutputC = (int)round(((float)$row['credit_net']) * 100);
            } elseif ($row['account_code'] === $vatInputCode) {
                $adjInputC = (int)round(((float)$row['debit_net']) * 100);
            }
        }
        $data['change_in_use_output_cents'] = $adjOutputC;
        $data['change_in_use_input_cents']  = $adjInputC;
        $data['box5_total_output_cents']    = $data['total_output_vat_cents'] + $adjOutputC;
        $data['box9_total_input_cents']     = $data['total_input_vat_cents'] + $adjInputC;
        $data['box10_net_cents']            = $data['box5_total_output_cents'] - $data['box9_total_input_cents'];
        return $data;
    }

    /**
     * VAT201 aggregates on the PAYMENTS (cash) basis — s15(2)/s16 VAT Act:
     * output tax is accounted for to the extent CONSIDERATION IS RECEIVED,
     * input tax to the extent payment is made. The GL stays accrual; only
     * VAT201 reporting changes.
     *
     * Method: each receipt/payment allocation dated in the period is
     * apportioned across the source document's per-tax-code profile
     * (fraction = allocation ÷ document total, applied to each tax code's
     * base and VAT in document-currency cents, translated at the document's
     * captured rate — consistent with the GL's s25D treatment). Credit notes
     * and vendor credits are recognised in full at ISSUE date (s21 applies
     * on both bases). Bank-matched transactions are inherently cash-dated
     * and are included at their transaction date.
     *
     * Known limitation (documented): unallocated receipts/customer deposits
     * have no document profile and are NOT included — the health check
     * warns when such receipts exist in a payments-basis period.
     */
    public static function calculatePaymentsBasis(
        PDO $db,
        int $companyId,
        string $startDate,
        string $endDate
    ): array {
        $agg = [
            'STD_BASE' => 0, 'STD_VAT' => 0, 'ZERO_BASE' => 0, 'EXEMPT_BASE' => 0,
            'UNTAGGED_BASE' => 0,
            'IN_CAPITAL' => 0, 'IN_OTHER' => 0,
        ];

        // Per-tax-code profile of an invoice/credit-note/bill line set:
        // ['<code>' => ['base' => cents, 'vat' => cents, 'capital' => bool]]
        $profile = function (array $lines, PDO $db, int $companyId): array {
            $p = [];
            foreach ($lines as $l) {
                $netC = (int)round(((float)$l['quantity'] * (float)$l['unit_price'] - (float)($l['discount'] ?? 0)) * 100);
                $rate = (float)($l['tax_rate'] ?? 0);
                $vatC = (int)round($netC * $rate / 100);
                $code = $l['tax_code'] ?? ($rate > 0.005 ? 'STD' : 'ZERO');
                $code = strtoupper($code ?: ($rate > 0.005 ? 'STD' : 'ZERO'));
                if (!isset($p[$code])) {
                    $p[$code] = ['base' => 0, 'vat' => 0, 'capital' => false];
                }
                $p[$code]['base'] += $netC;
                $p[$code]['vat'] += $vatC;
                if (!empty($l['is_capital'])) {
                    $p[$code]['capital'] = true;
                }
            }
            return $p;
        };

        // ---- OUTPUT: customer receipts apportioned over invoice profiles ----
        //
        // Rounding: RUNNING-TOTAL per (invoice, tax code, ZAR cents). Each
        // allocation recognises round(codeTotal × cumulativeFraction × fx)
        // minus what earlier allocations already recognised, so the deltas
        // telescope: an invoice's lifetime payments-basis VAT equals its
        // invoice-basis VAT exactly, however the receipts are split (per-
        // allocation independent rounding drifted ±1c per partial payment).
        $invoiceInfo = [];
        $loadInvoice = function (int $invId) use ($db, $profile, &$invoiceInfo): array {
            if (!isset($invoiceInfo[$invId])) {
                $hdr = $db->prepare("SELECT total, exchange_rate, currency FROM invoices WHERE id = ?");
                $hdr->execute([$invId]);
                $h = $hdr->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'exchange_rate' => 1, 'currency' => 'ZAR'];
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
                    'total'   => (float)$h['total'],
                    'fx'      => $fx,
                    'profile' => $profile($ls->fetchAll(PDO::FETCH_ASSOC), $db, 0),
                ];
            }
            return $invoiceInfo[$invId];
        };
        // Cumulative allocation fraction for an invoice up to (and incl.) a date.
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
                "SELECT pa.amount, p.payment_date
                   FROM payment_allocations pa
                   JOIN payments p ON p.id = pa.payment_id
                  WHERE pa.invoice_id = ? AND p.payment_date <= ?
                  ORDER BY p.payment_date, p.id, pa.id"
            );
            $als->execute([$invId, $endDate]);
            $cumAlloc = 0.0;
            $prev = []; // code => ['base' => zar cents, 'vat' => zar cents]
            foreach ($als->fetchAll(PDO::FETCH_ASSOC) as $al) {
                $cumAlloc += (float)$al['amount'];
                $frac = $cumAlloc / $inv['total'];
                foreach ($inv['profile'] as $code => $amounts) {
                    $newBase = (int)round($amounts['base'] * $frac * $inv['fx']);
                    $newVat  = (int)round($amounts['vat'] * $frac * $inv['fx']);
                    $dBase = $newBase - ($prev[$code]['base'] ?? 0);
                    $dVat  = $newVat - ($prev[$code]['vat'] ?? 0);
                    $prev[$code] = ['base' => $newBase, 'vat' => $newVat];
                    if ($al['payment_date'] >= $startDate) {
                        self::accumulateOutput($agg, $code, $dBase, $dVat);
                    }
                }
            }
        }

        // Credit notes (s21) at issue date — recognised only to the extent
        // output tax was previously accounted for on RECEIPTS of the linked
        // invoice (clawback). On the payments basis, crediting the UNPAID
        // portion of an invoice needs no adjustment (no output tax existed on
        // it), and a credit note against a never-paid invoice must not
        // manufacture a refund. Convention: a credit note absorbs the unpaid
        // balance first — recognition = the amount by which receipts-to-date
        // exceed the invoice's remaining (uncredited) capacity. Standalone
        // credit notes (no linked invoice) are recognised in full at issue —
        // they represent actual refunds; the health check flags them under
        // the payments basis. Linked credits use the INVOICE's fx rate so the
        // clawback nets exactly against the receipts it reverses.
        $stmt = $db->prepare(
            "SELECT cn.id, cn.invoice_id, cn.issue_date, cn.exchange_rate, cn.currency
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
            return $profile($ls->fetchAll(PDO::FETCH_ASSOC), $db, 0);
        };
        $processedInvoiceCns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $cn) {
            $linkedInvoiceId = !empty($cn['invoice_id']) ? (int)$cn['invoice_id'] : 0;
            if (!$linkedInvoiceId) {
                $fx = (strtoupper($cn['currency'] ?? 'ZAR') === 'ZAR' || (float)$cn['exchange_rate'] <= 0)
                    ? 1.0 : (float)$cn['exchange_rate'];
                foreach ($cnProfileFor((int)$cn['id']) as $code => $amounts) {
                    self::accumulateOutput($agg, $code,
                        -(int)round($amounts['base'] * $fx), -(int)round($amounts['vat'] * $fx));
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
            // Replay ALL posted CNs of this invoice up to the period end in
            // issue order; recognise the deltas of the ones issued in-window.
            $chain = $db->prepare(
                "SELECT id, issue_date FROM credit_notes
                  WHERE invoice_id = ? AND journal_id IS NOT NULL AND issue_date <= ?
                  ORDER BY issue_date, id"
            );
            $chain->execute([$linkedInvoiceId, $endDate]);
            $cumRaw = [];     // code => ['base','vat'] raw CN totals (ZAR cents)
            $prevRec = [];    // code => ['base','vat'] recognised cum after prior CNs
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
                    if ($link['issue_date'] >= $startDate && ($dBase !== 0 || $dVat !== 0)) {
                        self::accumulateOutput($agg, $code, -$dBase, -$dVat);
                    }
                }
            }
        }

        // ---- INPUT: supplier payments apportioned over bill profiles --------
        // Same running-total rounding as receipts: a bill's lifetime
        // payments-basis input VAT sums exactly to its per-line VAT.
        $stmt = $db->prepare(
            "SELECT DISTINCT apa.bill_id
               FROM ap_payment_allocations apa
               JOIN ap_payments p ON p.id = apa.ap_payment_id
              WHERE p.company_id = ? AND p.payment_date BETWEEN ? AND ?"
        );
        $stmt->execute([$companyId, $startDate, $endDate]);
        foreach (array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) as $billId) {
            $hdr = $db->prepare("SELECT total FROM ap_bills WHERE id = ?");
            $hdr->execute([$billId]);
            $billTotal = (float)$hdr->fetchColumn();
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
            foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $netC = (int)round(((float)$l['quantity'] * (float)$l['unit_price'] - (float)($l['discount'] ?? 0)) * 100);
                $vatC = (int)round($netC * (float)($l['tax_rate'] ?? 0) / 100);
                if (!empty($l['is_capital'])) {
                    $capVat += $vatC;
                } else {
                    $otherVat += $vatC;
                }
            }
            $als = $db->prepare(
                "SELECT apa.amount, p.payment_date
                   FROM ap_payment_allocations apa
                   JOIN ap_payments p ON p.id = apa.ap_payment_id
                  WHERE apa.bill_id = ? AND p.payment_date <= ?
                  ORDER BY p.payment_date, p.id, apa.id"
            );
            $als->execute([$billId, $endDate]);
            $cumAlloc = 0.0;
            $prevCap = 0;
            $prevOther = 0;
            foreach ($als->fetchAll(PDO::FETCH_ASSOC) as $al) {
                $cumAlloc += (float)$al['amount'];
                $frac = $cumAlloc / $billTotal;
                $newCap = (int)round($capVat * $frac);
                $newOther = (int)round($otherVat * $frac);
                if ($al['payment_date'] >= $startDate) {
                    $agg['IN_CAPITAL'] += $newCap - $prevCap;
                    $agg['IN_OTHER']   += $newOther - $prevOther;
                }
                $prevCap = $newCap;
                $prevOther = $newOther;
            }
        }

        // Vendor credits at issue date — full reversal, split capital/other
        // per line (a credit reversing a capital purchase must reduce the
        // CAPITAL input figure, mirroring the bill-side split above).
        // Documented limitation: unlike customer credit notes there is no
        // receipts clawback here — recognising the reversal early only
        // OVERSTATES the VAT payable (conservative), never claims a refund.
        $stmt = $db->prepare(
            "SELECT vc.id FROM vendor_credits vc
              WHERE vc.company_id = ? AND vc.issue_date BETWEEN ? AND ?
                AND vc.journal_id IS NOT NULL"
        );
        $stmt->execute([$companyId, $startDate, $endDate]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $vcId) {
            $ls = $db->prepare(
                "SELECT vcl.quantity, vcl.unit_price, vcl.discount, vcl.tax_rate,
                        (ga.account_subtype = 'fixed_asset') AS is_capital
                   FROM vendor_credit_lines vcl
                   LEFT JOIN gl_accounts ga ON ga.account_id = vcl.gl_account_id AND ga.company_id = ?
                  WHERE vcl.credit_id = ?"
            );
            $ls->execute([$companyId, (int)$vcId]);
            foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $netC = (int)round(((float)$l['quantity'] * (float)$l['unit_price'] - (float)($l['discount'] ?? 0)) * 100);
                $vatC = (int)round($netC * (float)($l['tax_rate'] ?? 0) / 100);
                if (!empty($l['is_capital'])) {
                    $agg['IN_CAPITAL'] -= $vatC;
                } else {
                    $agg['IN_OTHER'] -= $vatC;
                }
            }
        }

        // ---- Bank-matched items: inherently cash-dated ----------------------
        // Revenue lines on bank journals feed the output BASE figures.
        $stmt = $db->prepare(
            "SELECT COALESCE(tc.code, 'UNTAGGED') AS tax_code,
                    SUM(jl.credit - jl.debit) AS credit_net
               FROM journal_lines jl
               JOIN journal_entries je ON je.id = jl.journal_id
               JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id
               LEFT JOIN gl_tax_codes tc ON tc.tax_code_id = jl.tax_code_id
              WHERE je.company_id = ? AND je.status = 'posted'
                AND je.ref_type = 'bank_tx'
                AND je.entry_date BETWEEN ? AND ?
                AND ga.account_type = 'revenue'
              GROUP BY COALESCE(tc.code, 'UNTAGGED')"
        );
        $stmt->execute([$companyId, $startDate, $endDate]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = strtoupper($row['tax_code']) ?: 'UNTAGGED';
            self::accumulateOutput($agg, $code, (int)round(((float)$row['credit_net']) * 100), 0);
        }
        // Bank VAT legs: output VAT credited / input VAT debited on bank journals
        $stmt = $db->prepare(
            "SELECT jl.account_code, jl.is_capital_goods,
                    SUM(jl.credit - jl.debit) AS credit_net,
                    SUM(jl.debit - jl.credit) AS debit_net
               FROM journal_lines jl
               JOIN journal_entries je ON je.id = jl.journal_id
              WHERE je.company_id = ? AND je.status = 'posted'
                AND je.ref_type = 'bank_tx'
                AND je.entry_date BETWEEN ? AND ?
              GROUP BY jl.account_code, jl.is_capital_goods"
        );
        // Resolve the VAT account codes once for the bank leg attribution.
        require_once __DIR__ . '/AccountsMap.php';
        $map = new AccountsMap($db, $companyId);
        $outCode = $map->code('finance_vat_output_account_id');
        $inCode = $map->code('finance_vat_input_account_id');
        $stmt->execute([$companyId, $startDate, $endDate]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['account_code'] === $outCode) {
                $agg['STD_VAT'] += (int)round(((float)$row['credit_net']) * 100);
            } elseif ($row['account_code'] === $inCode) {
                if (!empty($row['is_capital_goods'])) {
                    $agg['IN_CAPITAL'] += (int)round(((float)$row['debit_net']) * 100);
                } else {
                    $agg['IN_OTHER'] += (int)round(((float)$row['debit_net']) * 100);
                }
            }
        }

        $totalOutputVat = $agg['STD_VAT'];
        $totalInputVat = $agg['IN_CAPITAL'] + $agg['IN_OTHER'];
        return [
            'output_standard_base_cents' => $agg['STD_BASE'],
            'output_standard_vat_cents'  => $agg['STD_VAT'],
            'output_zero_base_cents'     => $agg['ZERO_BASE'],
            'output_exempt_base_cents'   => $agg['EXEMPT_BASE'],
            'untagged_revenue_base_cents'=> $agg['UNTAGGED_BASE'],
            'total_output_vat_cents'     => $totalOutputVat,
            'input_capital_cents'        => $agg['IN_CAPITAL'],
            'input_other_cents'          => $agg['IN_OTHER'],
            // s22 relief needs output tax to have been accounted for; on the
            // payments basis the unpaid portion never was — no relief arises.
            'bad_debt_relief_input_cents'=> 0,
            'total_input_vat_cents'      => $totalInputVat,
            'net_vat_cents'              => $totalOutputVat - $totalInputVat,
        ];
    }

    /** Accumulate an output-side (base, VAT) pair into the aggregate by code. */
    private static function accumulateOutput(array &$agg, string $code, int $baseC, int $vatC): void
    {
        switch ($code) {
            case 'ZERO':
                $agg['ZERO_BASE'] += $baseC;
                break;
            case 'EXEMPT':
                $agg['EXEMPT_BASE'] += $baseC;
                break;
            case 'UNTAGGED':
                $agg['UNTAGGED_BASE'] += $baseC;
                break;
            default: // STD / STANDARD / other rated codes
                $agg['STD_BASE'] += $baseC;
                $agg['STD_VAT'] += $vatC;
        }
    }

    /**
     * Calculate VAT totals for a date range on the invoice (accrual) basis.
     *
     * @param PDO    $db
     * @param int    $companyId
     * @param string $startDate     YYYY-MM-DD
     * @param string $endDate       YYYY-MM-DD
     * @param string $vatOutputCode GL account code for output VAT
     * @param string $vatInputCode  GL account code for input VAT
     * @return array cents-denominated VAT201 aggregates
     */
    public static function calculate(
        PDO $db,
        int $companyId,
        string $startDate,
        string $endDate,
        string $vatOutputCode,
        string $vatInputCode,
        bool $excludeAdjustments = false
    ): array {
        // Module exclusions from the supplies/input measurement:
        //  - 'vat_settle': period-settlement clearing journals (output/input →
        //    control) would otherwise SUBTRACT the whole settled period from
        //    whichever period contains their date. Always excluded.
        //  - 'bad_debt': s22 write-off relief is measured separately below as
        //    its own input-side adjustment field. Always excluded here.
        //  - 'vat_adjust': manual VAT201 adjustments are reported in their own
        //    boxes (see vat201Boxes) — excluded only for box presentation.
        $exclModules = ['vat_settle', 'bad_debt'];
        if ($excludeAdjustments) {
            $exclModules[] = 'vat_adjust';
        }
        $adjFilter = " AND COALESCE(je.module,'') NOT IN ('" . implode("','", $exclModules) . "')";

        // --- Output VAT by tax code ---
        $stmt = $db->prepare(
            "SELECT
                COALESCE(tc.code, 'UNTAGGED') AS tax_code,
                SUM(jl.credit - jl.debit) AS vat_amount
             FROM journal_lines jl
             JOIN journal_entries je ON jl.journal_id = je.id
             LEFT JOIN gl_tax_codes tc ON jl.tax_code_id = tc.tax_code_id
             WHERE je.company_id = ? AND je.status = 'posted'
               AND je.entry_date BETWEEN ? AND ?
               AND jl.account_code = ?$adjFilter
             GROUP BY COALESCE(tc.code, 'UNTAGGED')"
        );
        $stmt->execute([$companyId, $startDate, $endDate, $vatOutputCode]);
        $outputStandardVat = 0.0;
        $totalOutputVat = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amt = (float)$row['vat_amount'];
            $totalOutputVat += $amt;
            $code = strtoupper($row['tax_code']);
            if ($code === 'ZERO' || $code === 'EXEMPT') {
                continue; // zero/exempt VAT should be 0 by definition
            }
            $outputStandardVat += $amt;
        }

        // --- Output tax BASE amounts from revenue lines, by tax code ---
        $stmt = $db->prepare(
            "SELECT
                COALESCE(tc.code, 'UNTAGGED') AS tax_code,
                SUM(jl.credit - jl.debit) AS base_amount
             FROM journal_lines jl
             JOIN journal_entries je ON jl.journal_id = je.id
             JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id
             LEFT JOIN gl_tax_codes tc ON jl.tax_code_id = tc.tax_code_id
             WHERE je.company_id = ? AND je.status = 'posted'
               AND je.entry_date BETWEEN ? AND ?
               AND ga.account_type = 'revenue'
             GROUP BY COALESCE(tc.code, 'UNTAGGED')"
        );
        $stmt->execute([$companyId, $startDate, $endDate]);
        $outputStandardBase = 0.0;
        $outputZeroBase = 0.0;
        $outputExemptBase = 0.0;
        $untaggedRevenueBase = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amt = (float)$row['base_amount'];
            switch (strtoupper($row['tax_code'])) {
                case 'ZERO':
                    $outputZeroBase += $amt;
                    break;
                case 'EXEMPT':
                    $outputExemptBase += $amt;
                    break;
                case 'UNTAGGED':
                    // Not silently standard-rated: reported for the health
                    // check. (Every posting path now tags revenue lines, so
                    // anything here is legacy or hand-crafted data.)
                    $untaggedRevenueBase += $amt;
                    break;
                default:
                    $outputStandardBase += $amt;
            }
        }

        // --- Input VAT: capital goods (Box 7) vs other, from the explicit
        // is_capital_goods flag written by the posting engine ---
        $stmt = $db->prepare(
            "SELECT
                CASE WHEN jl.is_capital_goods = 1 THEN 'capital' ELSE 'other' END AS input_category,
                SUM(jl.debit - jl.credit) AS vat_amount
             FROM journal_lines jl
             JOIN journal_entries je ON jl.journal_id = je.id
             WHERE je.company_id = ? AND je.status = 'posted'
               AND je.entry_date BETWEEN ? AND ?
               AND jl.account_code = ?$adjFilter
             GROUP BY input_category"
        );
        $stmt->execute([$companyId, $startDate, $endDate, $vatInputCode]);
        $inputCapital = 0.0;
        $inputOther = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amt = (float)$row['vat_amount'];
            if ($row['input_category'] === 'capital') {
                $inputCapital += $amt;
            } else {
                $inputOther += $amt;
            }
        }
        // --- s22 bad-debt relief (module 'bad_debt'): write-off journals
        // debit the VAT input account; SARS reports this in the input-side
        // "bad debts" adjustment field, never as a reduction of Box 1A. ---
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
             FROM journal_lines jl
             JOIN journal_entries je ON jl.journal_id = je.id
             WHERE je.company_id = ? AND je.status = 'posted'
               AND je.entry_date BETWEEN ? AND ?
               AND je.module = 'bad_debt'
               AND jl.account_code = ?"
        );
        $stmt->execute([$companyId, $startDate, $endDate, $vatInputCode]);
        $badDebtRelief = (float)$stmt->fetchColumn();

        $totalInputVat = $inputCapital + $inputOther + $badDebtRelief;

        return [
            'output_standard_base_cents' => (int)round($outputStandardBase * 100),
            'output_standard_vat_cents'  => (int)round($outputStandardVat * 100),
            'output_zero_base_cents'     => (int)round($outputZeroBase * 100),
            'output_exempt_base_cents'   => (int)round($outputExemptBase * 100),
            'untagged_revenue_base_cents'=> (int)round($untaggedRevenueBase * 100),
            'total_output_vat_cents'     => (int)round($totalOutputVat * 100),
            'input_capital_cents'        => (int)round($inputCapital * 100),
            'input_other_cents'          => (int)round($inputOther * 100),
            'bad_debt_relief_input_cents'=> (int)round($badDebtRelief * 100),
            'total_input_vat_cents'      => (int)round($totalInputVat * 100),
            'net_vat_cents'              => (int)round($totalOutputVat * 100) - (int)round($totalInputVat * 100),
        ];
    }
}
