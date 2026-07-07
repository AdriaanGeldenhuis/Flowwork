<?php
// finances/lib/PostingService.php
//
// PostingService is the CANONICAL posting engine: every journal that any
// module (QI invoicing, AR, AP, bank, payroll, fixed assets, VAT) writes to
// the general ledger goes through insertJournal() here, which enforces:
//   - at least two lines,
//   - integer-cents debits == credits,
//   - account codes that exist on the company's chart,
//   - an audit_log event per posted journal.
//
// Re-posting NEVER deletes a posted journal. supersedeJournal() reverses the
// old journal (linked via reverses_journal_id / reversed_by_journal_id) and
// reverses its inventory movements, then the caller posts the replacement —
// all inside one database transaction. Posted GL history is immutable.
//
// All post* methods open their own transaction unless the caller already has
// one, in which case they join it.

require_once __DIR__ . '/AccountsMap.php';
require_once __DIR__ . '/PeriodService.php';
require_once __DIR__ . '/InventoryService.php';
require_once __DIR__ . '/ReversalService.php';
require_once __DIR__ . '/Audit.php';

class PostingService
{
    private PDO $db;
    private int $companyId;
    private int $userId;
    private AccountsMap $accounts;
    private PeriodService $periodService;
    private InventoryService $inventory;
    private ReversalService $reversals;

    public function __construct(PDO $db, int $companyId, int $userId)
    {
        $this->db = $db;
        $this->companyId = $companyId;
        $this->userId = $userId;
        $this->accounts = new AccountsMap($db, $companyId);
        $this->periodService = new PeriodService($db, $companyId);
        $this->inventory = new InventoryService($db, $companyId);
        $this->reversals = new ReversalService($db, $companyId);
    }

    // ------------------------------------------------------------------
    // Tax code resolution
    // ------------------------------------------------------------------

    /** Resolve the input-side tax_code_id for an AP line based on its rate. */
    public function resolveInputTaxCodeId(float $taxRate): ?int
    {
        $code = $taxRate > 0.0001 ? 'INPUT' : 'ZERO';
        return $this->taxCodeIdByCode($code);
    }

    /** Resolve the output-side tax_code_id for an AR line based on its rate. */
    public function resolveOutputTaxCodeId(float $taxRate): ?int
    {
        $code = $taxRate > 0.0001 ? 'STD' : 'ZERO';
        $id = $this->taxCodeIdByCode($code);
        if (!$id && $code === 'STD') {
            $id = $this->taxCodeIdByCode('STANDARD');
        }
        return $id;
    }

    private function taxCodeIdByCode(string $code): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT tax_code_id FROM gl_tax_codes
              WHERE company_id = ? AND code = ? AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([$this->companyId, $code]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    // ------------------------------------------------------------------
    // Choke point: every journal insert goes through here
    // ------------------------------------------------------------------

    /**
     * Insert a posted journal with its lines after validating double-entry
     * integrity. Amounts are given in DECIMAL STRING or float rand; balance
     * is asserted in integer cents.
     *
     * $header keys: entry_date, reference, description, module, ref_type,
     *               ref_id, source_type, source_id.
     * $line keys:   account_code, description, debit, credit, and optional
     *               tax_code_id, is_capital_goods, customer_id, supplier_id,
     *               employee_id, project_id, board_id, item_id, reference.
     *
     * @return int journal id
     */
    private function insertJournal(array $header, array $lines): int
    {
        if (count($lines) < 2) {
            throw new Exception('Journal must have at least two lines');
        }
        $debitC = 0;
        $creditC = 0;
        $codes = [];
        foreach ($lines as $l) {
            $debitC  += (int)round(((float)($l['debit'] ?? 0)) * 100);
            $creditC += (int)round(((float)($l['credit'] ?? 0)) * 100);
            $codes[(string)$l['account_code']] = true;
        }
        if ($debitC !== $creditC) {
            throw new RuntimeException(sprintf(
                'Unbalanced journal blocked (%s): debits %s ≠ credits %s',
                $header['reference'] ?? '?',
                number_format($debitC / 100, 2, '.', ''),
                number_format($creditC / 100, 2, '.', '')
            ));
        }
        // Every line must hit a real account on this company's chart —
        // orphan codes silently vanish from reports that join gl_accounts.
        $codes = array_keys($codes);
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->db->prepare(
            "SELECT account_code FROM gl_accounts WHERE company_id = ? AND account_code IN ($ph)"
        );
        $stmt->execute(array_merge([$this->companyId], $codes));
        $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_diff($codes, $found);
        if ($missing) {
            throw new Exception('Unknown GL account code(s): ' . implode(', ', $missing)
                . ' — check Finance Settings account mappings');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO journal_entries (
                company_id, entry_date, reference, description, module, ref_type, ref_id,
                source_type, source_id, created_by, created_at, status, posted_by, posted_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'posted', ?, NOW())"
        );
        $stmt->execute([
            $this->companyId,
            $header['entry_date'],
            $header['reference'] ?? null,
            $header['description'] ?? null,
            $header['module'] ?? 'fin',
            $header['ref_type'] ?? null,
            $header['ref_id'] ?? null,
            $header['source_type'] ?? null,
            $header['source_id'] ?? null,
            $this->userId,
            $this->userId,
        ]);
        $journalId = (int)$this->db->lastInsertId();

        $stmtLine = $this->db->prepare(
            "INSERT INTO journal_lines
                (journal_id, account_code, description, debit, credit,
                 tax_code_id, is_capital_goods, customer_id, supplier_id, employee_id,
                 project_id, board_id, item_id, reference)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($lines as $l) {
            $stmtLine->execute([
                $journalId,
                $l['account_code'],
                $l['description'] ?? null,
                number_format((float)($l['debit'] ?? 0), 2, '.', ''),
                number_format((float)($l['credit'] ?? 0), 2, '.', ''),
                $l['tax_code_id'] ?? null,
                $l['is_capital_goods'] ?? 0,
                $l['customer_id'] ?? null,
                $l['supplier_id'] ?? null,
                $l['employee_id'] ?? null,
                $l['project_id'] ?? null,
                $l['board_id'] ?? null,
                $l['item_id'] ?? null,
                $l['reference'] ?? ($header['reference'] ?? null),
            ]);
        }

        Audit::log('journal_posted', [
            'reference' => $header['reference'] ?? null,
            'source_type' => $header['source_type'] ?? null,
            'source_id' => $header['source_id'] ?? null,
            'total_cents' => $debitC,
        ], 'journal', $journalId, $this->companyId, $this->userId);

        return $journalId;
    }

    /**
     * Supersede a previously posted journal: reverse it (never delete) and
     * reverse any inventory movements it created. The reversal is dated on
     * the original entry date when that period is open, otherwise on
     * $fallbackDate (which the caller has already validated as unlocked).
     *
     * @throws Exception when neither date is postable
     */
    private function supersedeJournal(int $journalId, string $reason, string $fallbackDate): void
    {
        if (!$journalId) {
            return;
        }
        $stmt = $this->db->prepare(
            "SELECT entry_date, status, reversed_by_journal_id FROM journal_entries
              WHERE id = ? AND company_id = ?"
        );
        $stmt->execute([$journalId, $this->companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return; // journal no longer exists — nothing to supersede
        }
        if (!empty($row['reversed_by_journal_id'])) {
            return; // already reversed (e.g. a prior failed repost) — idempotent
        }
        if ($row['status'] !== 'posted') {
            // Legacy engines left journals in 'draft' (the pre-status era never
            // set status), so they were never IN the posted reports. Posting a
            // reversal for one would SUBTRACT amounts that were never added —
            // reposting such a document would net its history to zero in every
            // posted-only report. Reverse only its linked stock movements (the
            // movements were real regardless of journal status) and detach.
            $this->inventory->reverseMovementsForJournal($journalId, null, $fallbackDate);
            Audit::log('journal_superseded_unposted', [
                'journal_id' => $journalId,
                'status' => $row['status'],
                'reason' => $reason,
            ], 'journal', $journalId, $this->companyId, $this->userId);
            return;
        }
        $reversalDate = $this->periodService->isLocked($row['entry_date'])
            ? $fallbackDate
            : $row['entry_date'];
        if ($this->periodService->isLocked($reversalDate)) {
            throw new Exception(
                'Cannot supersede journal #' . $journalId . ': period is locked (' . $reversalDate . ')'
            );
        }
        $reversalId = $this->reversals->reverseJournal($journalId, $this->userId, $reason, $reversalDate);
        $this->inventory->reverseMovementsForJournal($journalId, $reversalId, $reversalDate);
    }

    /**
     * Run $fn inside a transaction, joining the caller's transaction when one
     * is already open (callers like AP payment endpoints wrap posting +
     * document updates in a single transaction).
     */
    private function withTransaction(callable $fn)
    {
        if ($this->db->inTransaction()) {
            return $fn();
        }
        $this->db->beginTransaction();
        try {
            $result = $fn();
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Public entry for system endpoints that must post a one-off journal
     * (manual VAT adjustments, inventory returns) without a postX() document
     * flow. Same choke-point guarantees as every engine posting: period lock,
     * ≥2 lines, integer-cents balance assertion, account existence, audit
     * trail, status 'posted', transaction (joins the caller's when open).
     */
    public function postAdHocJournal(array $header, array $lines): int
    {
        if (empty($header['entry_date'])) {
            throw new Exception('Journal entry_date is required');
        }
        if ($this->periodService->isLocked($header['entry_date'])) {
            throw new Exception('Cannot post into locked period (' . $header['entry_date'] . ')');
        }
        return $this->withTransaction(fn () => $this->insertJournal($header, $lines));
    }

    /** Resolve a gl_bank_accounts row to its GL account code (with fallback). */
    private function bankCodeFor($bankAccountId): string
    {
        if ($bankAccountId) {
            $stmt = $this->db->prepare(
                "SELECT ga.account_code FROM gl_bank_accounts ba
                  JOIN gl_accounts ga ON ga.account_id = ba.gl_account_id
                 WHERE ba.id = ? AND ba.company_id = ? LIMIT 1"
            );
            $stmt->execute([(int)$bankAccountId, $this->companyId]);
            $code = $stmt->fetchColumn();
            if ($code) {
                return $code;
            }
        }
        return $this->accounts->code('finance_bank_account_id');
    }

    /** FX helper: [currency, rate] for a document row (ledger is ZAR, s25D). */
    private function resolveFx(array $row): array
    {
        $currency = strtoupper(trim($row['currency'] ?? 'ZAR')) ?: 'ZAR';
        $rate = (float)($row['exchange_rate'] ?? 1);
        if ($currency === 'ZAR' || $rate <= 0) {
            return ['ZAR', 1.0];
        }
        return [$currency, $rate];
    }

    private function fxNote(string $currency, float $rate): string
    {
        if ($currency === 'ZAR') {
            return '';
        }
        return ' (' . $currency . ' @ ' . rtrim(rtrim(number_format($rate, 6, '.', ''), '0'), '.') . ')';
    }

    // ------------------------------------------------------------------
    // Invoices (AR)
    // ------------------------------------------------------------------

    /**
     * Post an invoice: debit AR, credit Sales per (account, tax code), credit
     * VAT Output per tax code; issue stock and book COGS for inventory lines.
     * Re-posting reverses the previous journal (and its stock movements)
     * before posting the replacement — atomically.
     *
     * @param int      $invoiceId
     * @param int|null $projectId optional project tag applied to all lines
     */
    public function postInvoice(int $invoiceId, ?int $projectId = null): void
    {
        $this->withTransaction(function () use ($invoiceId, $projectId) {
            $stmt = $this->db->prepare(
                "SELECT * FROM invoices WHERE id = ? AND company_id = ? LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$invoiceId, $this->companyId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                throw new Exception('Invoice not found');
            }
            $entryDate = $invoice['issue_date'] ?? date('Y-m-d');
            if ($this->periodService->isLocked($entryDate)) {
                throw new Exception('Cannot post to locked period (' . $entryDate . ')');
            }
            if (!empty($invoice['journal_id'])) {
                $this->supersedeJournal((int)$invoice['journal_id'],
                    'Invoice ' . $invoice['invoice_number'] . ' re-posted', $entryDate);
            }

            $stmt = $this->db->prepare(
                "SELECT quantity, unit_price, discount, tax_rate, tax_code_id, gl_account_id, inventory_item_id
                   FROM invoice_lines WHERE invoice_id = ?"
            );
            $stmt->execute([$invoiceId]);
            $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$lines) {
                throw new Exception('Invoice has no lines');
            }

            $arCode    = $this->accounts->code('finance_ar_account_id');
            $salesDef  = $this->accounts->code('finance_sales_account_id');
            $vatCode   = $this->accounts->code('finance_vat_output_account_id');
            $invCode   = $this->accounts->code('finance_inventory_account_id');
            $cogsCode  = $this->accounts->code('finance_cogs_account_id');

            [$fxCurrency, $fxRate] = $this->resolveFx($invoice);
            $reference = $invoice['invoice_number'];
            $customerId = $invoice['customer_id'] ?: null;

            // Group revenue and VAT per (account, tax code) in integer cents
            // of document currency, then translate group totals to ZAR.
            $salesGroups = [];   // key "code|taxCodeId" => net cents
            $vatGroups   = [];   // taxCodeId => vat cents
            $pendingIssues = []; // inventory issues to record after the journal
            $cogsC = 0;
            foreach ($lines as $li) {
                $qty      = (float)$li['quantity'];
                $price    = (float)$li['unit_price'];
                $discount = (float)($li['discount'] ?? 0);
                $taxRate  = (float)($li['tax_rate'] ?? 0);
                $netC     = (int)round(($qty * $price - $discount) * 100);
                $vatC     = (int)round($netC * $taxRate / 100);

                $taxCodeId = $li['tax_code_id'] ? (int)$li['tax_code_id']
                    : $this->resolveOutputTaxCodeId($taxRate);
                $code = $this->accounts->getById($li['gl_account_id'] ? (int)$li['gl_account_id'] : null) ?: $salesDef;

                $gkey = $code . '|' . ($taxCodeId ?? '');
                $salesGroups[$gkey] = ($salesGroups[$gkey] ?? 0) + $netC;
                if ($vatC !== 0) {
                    $vatGroups[$taxCodeId ?? 0] = ($vatGroups[$taxCodeId ?? 0] ?? 0) + $vatC;
                }

                $invItemId = !empty($li['inventory_item_id']) ? (int)$li['inventory_item_id'] : null;
                if ($invItemId && abs($qty) > 0.0001) {
                    // qty < 0 = customer return netted on the invoice: it must
                    // restock and credit COGS, not just reduce revenue.
                    $pendingIssues[] = ['item_id' => $invItemId, 'qty' => $qty];
                }
            }

            $toZar = fn(int $cents): float => round(($cents / 100) * $fxRate, 2);

            $journalLines = [];
            $totalZar = 0.0;
            foreach ($salesGroups as $gkey => $netC) {
                [$code, $taxCodeId] = explode('|', $gkey);
                $amt = $toZar($netC);
                if (abs($amt) < 0.005) {
                    continue;
                }
                $journalLines[] = [
                    'account_code' => $code,
                    'description'  => 'Sales Income',
                    'debit'        => $amt < 0 ? -$amt : 0,
                    'credit'       => $amt > 0 ? $amt : 0,
                    'tax_code_id'  => $taxCodeId !== '' ? (int)$taxCodeId : null,
                    'customer_id'  => $customerId,
                    'project_id'   => $projectId,
                ];
                $totalZar += $amt;
            }
            foreach ($vatGroups as $taxCodeId => $vatC) {
                $amt = $toZar($vatC);
                if (abs($amt) < 0.005) {
                    continue;
                }
                $journalLines[] = [
                    'account_code' => $vatCode,
                    'description'  => 'VAT Output',
                    'debit'        => $amt < 0 ? -$amt : 0,
                    'credit'       => $amt > 0 ? $amt : 0,
                    'tax_code_id'  => $taxCodeId ?: null,
                    'customer_id'  => $customerId,
                    'project_id'   => $projectId,
                ];
                $totalZar += $amt;
            }
            // AR debit derived from the sum of parts so the journal balances
            // to the cent by construction (asserted again in insertJournal).
            array_unshift($journalLines, [
                'account_code' => $arCode,
                'description'  => 'Accounts Receivable',
                'debit'        => round($totalZar, 2),
                'credit'       => 0,
                'customer_id'  => $customerId,
                'project_id'   => $projectId,
            ]);

            // Issue stock (weighted average) and book COGS. Costs are ZAR.
            $movementIds = [];
            foreach ($pendingIssues as $pi) {
                if ($pi['qty'] > 0) {
                    $cost = $this->inventory->issue($pi['item_id'], $pi['qty'], $entryDate, 'invoice', $invoiceId);
                    $movementIds[] = (int)$this->db->lastInsertId();
                    $cogsC += (int)round($cost * 100);
                } else {
                    // Return line: receive back at current weighted average
                    // cost and reverse COGS symmetrically.
                    $unitCost = $this->inventory->getAverageCost($pi['item_id']);
                    $cost = $this->inventory->receive($pi['item_id'], -$pi['qty'], $unitCost, $entryDate, 'invoice', $invoiceId);
                    $movementIds[] = (int)$this->db->lastInsertId();
                    $cogsC -= (int)round($cost * 100);
                }
            }
            if ($cogsC !== 0) {
                $journalLines[] = [
                    'account_code' => $cogsCode,
                    'description'  => 'Cost of Goods Sold',
                    'debit'        => $cogsC > 0 ? $cogsC / 100 : 0,
                    'credit'       => $cogsC < 0 ? -$cogsC / 100 : 0,
                    'customer_id'  => $customerId,
                    'project_id'   => $projectId,
                ];
                $journalLines[] = [
                    'account_code' => $invCode,
                    'description'  => 'Inventory',
                    'debit'        => $cogsC < 0 ? -$cogsC / 100 : 0,
                    'credit'       => $cogsC > 0 ? $cogsC / 100 : 0,
                    'customer_id'  => $customerId,
                    'project_id'   => $projectId,
                ];
            }

            $journalId = $this->insertJournal([
                'entry_date'  => $entryDate,
                'reference'   => $reference,
                'description' => 'Invoice ' . $reference . $this->fxNote($fxCurrency, $fxRate),
                'ref_type'    => 'invoice',
                'ref_id'      => $invoiceId,
                'source_type' => 'invoice',
                'source_id'   => $invoiceId,
            ], $journalLines);

            if ($movementIds) {
                $ph = implode(',', array_fill(0, count($movementIds), '?'));
                $this->db->prepare("UPDATE inventory_movements SET journal_id = ? WHERE id IN ($ph)")
                    ->execute(array_merge([$journalId], $movementIds));
            }

            $this->db->prepare("UPDATE invoices SET journal_id = ? WHERE id = ? AND company_id = ?")
                ->execute([$journalId, $invoiceId, $this->companyId]);
        });
    }

    /**
     * Back an invoice out of the GL (void / cancel / revert-to-draft):
     * reverse its journal and inventory movements, clear the journal link.
     * Throws when the reversal cannot be posted (locked period) so the
     * calling status change rolls back with it — a voided invoice must never
     * keep its revenue and VAT in the ledger.
     */
    public function unpostInvoice(int $invoiceId, string $reason): void
    {
        $this->withTransaction(function () use ($invoiceId, $reason) {
            $stmt = $this->db->prepare(
                "SELECT journal_id, invoice_number FROM invoices
                  WHERE id = ? AND company_id = ? LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$invoiceId, $this->companyId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice || empty($invoice['journal_id'])) {
                return;
            }
            $this->supersedeJournal((int)$invoice['journal_id'], $reason, date('Y-m-d'));
            $this->db->prepare("UPDATE invoices SET journal_id = NULL WHERE id = ? AND company_id = ?")
                ->execute([$invoiceId, $this->companyId]);
        });
    }

    /**
     * Post a bad-debt write-off for an invoice: debit Bad Debts (net), debit
     * VAT Output for the tax fraction (s22(1) VAT Act — output tax relief on
     * consideration that has become irrecoverable, invoice basis), credit AR.
     * The VAT portion uses the invoice's own tax-to-total ratio so partial
     * write-offs relieve proportional VAT.
     */
    public function postInvoiceWriteOff(int $invoiceId, float $amount, string $reason): void
    {
        $this->withTransaction(function () use ($invoiceId, $amount, $reason) {
            $stmt = $this->db->prepare(
                "SELECT invoice_number, customer_id, total, tax, issue_date, currency, exchange_rate
                   FROM invoices WHERE id = ? AND company_id = ? LIMIT 1"
            );
            $stmt->execute([$invoiceId, $this->companyId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                throw new Exception('Invoice not found');
            }
            $entryDate = date('Y-m-d');
            if ($this->periodService->isLocked($entryDate)) {
                throw new Exception('Cannot post write-off to locked period (' . $entryDate . ')');
            }
            // $amount arrives in DOCUMENT currency (it is capped against
            // balance_due); the ledger is ZAR — translate at the invoice's
            // captured rate (s25D), like every other posting of this document.
            [$fxCurrency, $fxRate] = $this->resolveFx($invoice);
            $amountC = (int)round($amount * 100);
            if ($amountC <= 0) {
                return;
            }
            $total = (float)$invoice['total'];
            $taxRatio = $total > 0 ? ((float)$invoice['tax'] / $total) : 0.0;
            $vatC = (int)round($amountC * $taxRatio);
            $netC = $amountC - $vatC;
            $toZar = fn(int $cents): float => round(($cents / 100) * $fxRate, 2);
            $netZar = $toZar($netC);
            $vatZar = $toZar($vatC);

            $lines = [];
            if ($netZar > 0) {
                $lines[] = [
                    'account_code' => $this->accounts->code('finance_bad_debt_account_id'),
                    'description'  => 'Bad debt written off — ' . $invoice['invoice_number'],
                    'debit'        => $netZar,
                    'credit'       => 0,
                    'customer_id'  => $invoice['customer_id'] ?: null,
                ];
            }
            if ($vatZar > 0) {
                // s22(1) relief is an INPUT TAX deduction on the VAT201 (the
                // bad-debts adjustment field on the input side), NOT a
                // reduction of Box 1A — eFiling derives field 4 from field 1,
                // so netting output would break the form's own arithmetic.
                // Posted to the VAT input account; measured separately via
                // the journal's module (see VatCalculator).
                $lines[] = [
                    'account_code' => $this->accounts->code('finance_vat_input_account_id'),
                    'description'  => 'VAT relief on bad debt (s22) — ' . $invoice['invoice_number'],
                    'debit'        => $vatZar,
                    'credit'       => 0,
                    'tax_code_id'  => $this->resolveInputTaxCodeId(15.0),
                    'customer_id'  => $invoice['customer_id'] ?: null,
                ];
            }
            $lines[] = [
                'account_code' => $this->accounts->code('finance_ar_account_id'),
                'description'  => 'Accounts Receivable written off',
                'debit'        => 0,
                'credit'       => round($netZar + $vatZar, 2),
                'customer_id'  => $invoice['customer_id'] ?: null,
            ];

            $this->insertJournal([
                'entry_date'  => $entryDate,
                'reference'   => 'WO-' . $invoice['invoice_number'],
                'description' => 'Write-off ' . $invoice['invoice_number'] . ($reason ? ' — ' . $reason : '')
                    . $this->fxNote($fxCurrency, $fxRate),
                'module'      => 'bad_debt',
                'ref_type'    => 'invoice_write_off',
                'ref_id'      => $invoiceId,
                'source_type' => 'invoice_write_off',
                'source_id'   => $invoiceId,
            ], $lines);
        });
    }

    // ------------------------------------------------------------------
    // Customer payments (AR receipts)
    // ------------------------------------------------------------------

    public function postCustomerPayment(int $paymentId): void
    {
        $this->withTransaction(function () use ($paymentId) {
            $stmt = $this->db->prepare(
                "SELECT * FROM payments WHERE id = ? AND company_id = ? LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$paymentId, $this->companyId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$payment) {
                throw new Exception('Payment not found');
            }
            $entryDate = $payment['payment_date'] ?? date('Y-m-d');
            if ($this->periodService->isLocked($entryDate)) {
                throw new Exception('Cannot post payment to locked period (' . $entryDate . ')');
            }
            if (!empty($payment['journal_id'])) {
                $this->supersedeJournal((int)$payment['journal_id'],
                    'Payment #' . $paymentId . ' re-posted', $entryDate);
            }

            $stmt = $this->db->prepare(
                "SELECT pa.amount, i.customer_id, i.invoice_number, i.currency, i.exchange_rate
                   FROM payment_allocations pa
                   LEFT JOIN invoices i ON pa.invoice_id = i.id
                  WHERE pa.payment_id = ?"
            );
            $stmt->execute([$paymentId]);
            $allocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$allocs) {
                throw new Exception('Payment has no allocations');
            }

            $bankCode = $this->bankCodeFor($payment['bank_account_id'] ?? null);
            $arCode = $this->accounts->code('finance_ar_account_id');
            $reference = $payment['reference'] ?: ('PAY' . $paymentId);

            // AR is relieved at each invoice's captured rate; the bank leg is
            // booked at the payment-date rate when one was captured, with the
            // difference recognised as realised FX gain/loss (s24I / s25D).
            $journalLines = [];
            $arZar = 0.0;
            foreach ($allocs as $al) {
                [, $rate] = $this->resolveFx($al);
                $amt = round((float)$al['amount'] * $rate, 2);
                $arZar += $amt;
                $journalLines[] = [
                    'account_code' => $arCode,
                    'description'  => 'Accounts Receivable',
                    'debit'        => 0,
                    'credit'       => $amt,
                    'customer_id'  => $al['customer_id'] ?: null,
                    'reference'    => $al['invoice_number'] ?: $reference,
                ];
            }

            $paymentRate = (float)($payment['exchange_rate'] ?? 0);
            $bankZar = $arZar;
            if ($paymentRate > 0) {
                $docTotal = 0.0;
                foreach ($allocs as $al) {
                    $docTotal += (float)$al['amount'];
                }
                $candidate = round($docTotal * $paymentRate, 2);
                // Only treat as FX settlement when an allocation was foreign.
                foreach ($allocs as $al) {
                    [$cur] = $this->resolveFx($al);
                    if ($cur !== 'ZAR') {
                        $bankZar = $candidate;
                        break;
                    }
                }
            }
            array_unshift($journalLines, [
                'account_code' => $bankCode,
                'description'  => 'Bank',
                'debit'        => $bankZar,
                'credit'       => 0,
                'reference'    => $reference,
            ]);
            $fxDiff = round($bankZar - $arZar, 2);
            if (abs($fxDiff) >= 0.01) {
                $journalLines[] = $fxDiff > 0
                    ? [
                        'account_code' => $this->accounts->code('finance_fx_gain_account_id'),
                        'description'  => 'Realised FX gain on settlement',
                        'debit'        => 0,
                        'credit'       => $fxDiff,
                    ]
                    : [
                        'account_code' => $this->accounts->code('finance_fx_loss_account_id'),
                        'description'  => 'Realised FX loss on settlement',
                        'debit'        => -$fxDiff,
                        'credit'       => 0,
                    ];
            }

            $journalId = $this->insertJournal([
                'entry_date'  => $entryDate,
                'reference'   => $reference,
                'description' => 'Payment',
                'ref_type'    => 'payment',
                'ref_id'      => $paymentId,
                'source_type' => 'payment',
                'source_id'   => $paymentId,
            ], $journalLines);

            $this->db->prepare("UPDATE payments SET journal_id = ? WHERE id = ? AND company_id = ?")
                ->execute([$journalId, $paymentId, $this->companyId]);
        });
    }

    /** Back-compat alias used by retargeted QI callers. */
    public function postPayment(int $paymentId): void
    {
        $this->postCustomerPayment($paymentId);
    }

    // ------------------------------------------------------------------
    // Credit notes (AR)
    // ------------------------------------------------------------------

    public function postCreditNote(int $creditNoteId): void
    {
        $this->withTransaction(function () use ($creditNoteId) {
            $stmt = $this->db->prepare(
                "SELECT cn.*, COALESCE(cn.customer_id, i.customer_id) AS effective_customer_id
                   FROM credit_notes cn
                   LEFT JOIN invoices i ON cn.invoice_id = i.id
                  WHERE cn.id = ? AND cn.company_id = ? LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$creditNoteId, $this->companyId]);
            $credit = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$credit) {
                throw new Exception('Credit note not found');
            }
            $entryDate = $credit['issue_date'] ?? date('Y-m-d');
            if ($this->periodService->isLocked($entryDate)) {
                throw new Exception('Cannot post credit note to locked period (' . $entryDate . ')');
            }
            if (!empty($credit['journal_id'])) {
                $this->supersedeJournal((int)$credit['journal_id'],
                    'Credit note ' . $credit['credit_note_number'] . ' re-posted', $entryDate);
            }

            $stmt = $this->db->prepare(
                "SELECT quantity, unit_price, discount, tax_rate, tax_code_id
                   FROM credit_note_lines WHERE credit_note_id = ?"
            );
            $stmt->execute([$creditNoteId]);
            $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$lines) {
                throw new Exception('Credit note has no lines');
            }

            $arCode   = $this->accounts->code('finance_ar_account_id');
            $salesDef = $this->accounts->code('finance_sales_account_id');
            $vatCode  = $this->accounts->code('finance_vat_output_account_id');
            [$fxCurrency, $fxRate] = $this->resolveFx($credit);
            $reference = $credit['credit_note_number'];
            $customerId = $credit['effective_customer_id'] ?: null;

            // Per-tax-code grouping, mirroring postInvoice (reversed sides).
            $netGroups = [];
            $vatGroups = [];
            foreach ($lines as $li) {
                $netC = (int)round(((float)$li['quantity'] * (float)$li['unit_price'] - (float)($li['discount'] ?? 0)) * 100);
                $rate = (float)($li['tax_rate'] ?? 0);
                $vatC = (int)round($netC * $rate / 100);
                $taxCodeId = $li['tax_code_id'] ? (int)$li['tax_code_id'] : $this->resolveOutputTaxCodeId($rate);
                $netGroups[$taxCodeId ?? 0] = ($netGroups[$taxCodeId ?? 0] ?? 0) + $netC;
                if ($vatC !== 0) {
                    $vatGroups[$taxCodeId ?? 0] = ($vatGroups[$taxCodeId ?? 0] ?? 0) + $vatC;
                }
            }
            $toZar = fn(int $cents): float => round(($cents / 100) * $fxRate, 2);

            $journalLines = [];
            $totalZar = 0.0;
            foreach ($netGroups as $taxCodeId => $netC) {
                $amt = $toZar($netC);
                if (abs($amt) < 0.005) {
                    continue;
                }
                $journalLines[] = [
                    'account_code' => $salesDef,
                    'description'  => 'Sales Return',
                    'debit'        => $amt,
                    'credit'       => 0,
                    'tax_code_id'  => $taxCodeId ?: null,
                    'customer_id'  => $customerId,
                ];
                $totalZar += $amt;
            }
            foreach ($vatGroups as $taxCodeId => $vatC) {
                $amt = $toZar($vatC);
                if (abs($amt) < 0.005) {
                    continue;
                }
                $journalLines[] = [
                    'account_code' => $vatCode,
                    'description'  => 'VAT Output (Credit Note)',
                    'debit'        => $amt,
                    'credit'       => 0,
                    'tax_code_id'  => $taxCodeId ?: null,
                    'customer_id'  => $customerId,
                ];
                $totalZar += $amt;
            }
            $journalLines[] = [
                'account_code' => $arCode,
                'description'  => 'Accounts Receivable',
                'debit'        => 0,
                'credit'       => round($totalZar, 2),
                'customer_id'  => $customerId,
            ];

            $journalId = $this->insertJournal([
                'entry_date'  => $entryDate,
                'reference'   => $reference,
                'description' => 'Credit Note ' . $reference . $this->fxNote($fxCurrency, $fxRate),
                'ref_type'    => 'credit_note',
                'ref_id'      => $creditNoteId,
                'source_type' => 'credit_note',
                'source_id'   => $creditNoteId,
            ], $journalLines);

            $this->db->prepare("UPDATE credit_notes SET journal_id = ? WHERE id = ? AND company_id = ?")
                ->execute([$journalId, $creditNoteId, $this->companyId]);
        });
    }

    // ------------------------------------------------------------------
    // Payroll
    // ------------------------------------------------------------------

    /**
     * Post a payroll run. Debits wage expense (gross + reimbursements −
     * other deductions) plus separate employer UIF/SDL expense lines; credits
     * bank (net pay), PAYE, UIF (employee+employer) and SDL. The bank account
     * must be configured in payroll settings — never guessed. Any imbalance
     * (bad run data) is blocked by the choke-point assertion.
     */
    public function postPayrollRun(int $runId): void
    {
        $this->withTransaction(function () use ($runId) {
            $stmt = $this->db->prepare(
                "SELECT pr.*, ps.default_wage_gl_code, ps.default_paye_gl_code,
                        ps.default_uif_gl_code, ps.default_sdl_gl_code, ps.default_bank_account_id
                   FROM pay_runs pr
                   LEFT JOIN payroll_settings ps ON ps.company_id = pr.company_id
                  WHERE pr.id = ? AND pr.company_id = ? LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$runId, $this->companyId]);
            $run = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$run) {
                throw new Exception('Payroll run not found');
            }
            $entryDate = $run['pay_date'] ?: date('Y-m-d');
            if ($this->periodService->isLocked($entryDate)) {
                throw new Exception('Cannot post payroll run to locked period (' . $entryDate . ')');
            }
            if (!empty($run['journal_id'])) {
                $this->supersedeJournal((int)$run['journal_id'],
                    'Payroll run #' . $runId . ' re-posted', $entryDate);
            }

            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(gross_cents),0) gross,
                        COALESCE(SUM(paye_cents),0) paye,
                        COALESCE(SUM(uif_employee_cents),0) uif_emp,
                        COALESCE(SUM(uif_employer_cents),0) uif_empr,
                        COALESCE(SUM(sdl_cents),0) sdl,
                        COALESCE(SUM(other_deductions_cents),0) other_ded,
                        COALESCE(SUM(reimbursements_cents),0) reimburse,
                        COALESCE(SUM(bank_amount_cents),0) bank
                   FROM pay_run_employees WHERE run_id = ? AND company_id = ?"
            );
            $stmt->execute([$runId, $this->companyId]);
            $t = $stmt->fetch(PDO::FETCH_ASSOC);
            $grossC = (int)$t['gross'];
            $payeC = (int)$t['paye'];
            $uifEmpC = (int)$t['uif_emp'];
            $uifEmprC = (int)$t['uif_empr'];
            $sdlC = (int)$t['sdl'];
            $otherDedC = (int)$t['other_ded'];
            $reimburseC = (int)$t['reimburse'];
            $bankC = (int)$t['bank'];

            if ($grossC === 0 && $bankC === 0) {
                return; // nothing to post
            }

            // Account resolution: finance_* mapping (subtype-seeded, chart-safe)
            // first, then the payroll_settings code, then the seed default.
            $resolve = function (string $mapKey, ?string $settingsCode) {
                $code = $this->accounts->get($mapKey, '');
                if ($code !== '') {
                    return $code;
                }
                return $settingsCode ?: AccountsMap::DEFAULTS[$mapKey];
            };
            $wageCode = $resolve('finance_wage_expense_account_id', $run['default_wage_gl_code'] ?? null);
            $payeCode = $resolve('finance_paye_account_id', $run['default_paye_gl_code'] ?? null);
            $uifCode  = $resolve('finance_uif_account_id', $run['default_uif_gl_code'] ?? null);
            $sdlCode  = $resolve('finance_sdl_account_id', $run['default_sdl_gl_code'] ?? null);
            $uifExpCode = $this->accounts->code('finance_uif_expense_account_id');
            $sdlExpCode = $this->accounts->code('finance_sdl_expense_account_id');

            if (empty($run['default_bank_account_id'])) {
                throw new Exception('Payroll bank account not configured — set it in Payroll Settings before posting');
            }
            $bankCode = $this->bankCodeFor((int)$run['default_bank_account_id']);

            $reference = $run['run_number'] ?: ('PR' . $runId);
            $lines = [];
            $wageDebitC = $grossC + $reimburseC - $otherDedC;
            if ($wageDebitC > 0) {
                $lines[] = ['account_code' => $wageCode, 'description' => 'Payroll Wages Expense',
                    'debit' => $wageDebitC / 100, 'credit' => 0];
            }
            if ($uifEmprC > 0) {
                $lines[] = ['account_code' => $uifExpCode, 'description' => 'UIF — Employer Contribution',
                    'debit' => $uifEmprC / 100, 'credit' => 0];
            }
            if ($sdlC > 0) {
                $lines[] = ['account_code' => $sdlExpCode, 'description' => 'SDL — Employer',
                    'debit' => $sdlC / 100, 'credit' => 0];
            }
            if ($bankC > 0) {
                $lines[] = ['account_code' => $bankCode, 'description' => 'Payroll Net Pay',
                    'debit' => 0, 'credit' => $bankC / 100];
            }
            if ($payeC > 0) {
                $lines[] = ['account_code' => $payeCode, 'description' => 'PAYE Payable',
                    'debit' => 0, 'credit' => $payeC / 100];
            }
            if ($uifEmpC + $uifEmprC > 0) {
                $lines[] = ['account_code' => $uifCode, 'description' => 'UIF Payable',
                    'debit' => 0, 'credit' => ($uifEmpC + $uifEmprC) / 100];
            }
            if ($sdlC > 0) {
                $lines[] = ['account_code' => $sdlCode, 'description' => 'SDL Payable',
                    'debit' => 0, 'credit' => $sdlC / 100];
            }

            $journalId = $this->insertJournal([
                'entry_date'  => $entryDate,
                'reference'   => $reference,
                'description' => 'Payroll Run ' . $reference,
                'module'      => 'payroll',
                'ref_type'    => 'pay_run',
                'ref_id'      => $runId,
                'source_type' => 'payroll',
                'source_id'   => $runId,
            ], $lines);

            $this->db->prepare("UPDATE pay_runs SET journal_id = ? WHERE id = ? AND company_id = ?")
                ->execute([$journalId, $runId, $this->companyId]);
        });
    }

    // ------------------------------------------------------------------
    // Depreciation
    // ------------------------------------------------------------------

    public function postDepreciation(int $runId): void
    {
        $this->withTransaction(function () use ($runId) {
            $stmt = $this->db->prepare(
                "SELECT id, company_id, run_month, status, journal_id
                   FROM fa_depreciation_runs WHERE id = ? AND company_id = ? FOR UPDATE"
            );
            $stmt->execute([$runId, $this->companyId]);
            $run = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$run) {
                throw new Exception('Depreciation run not found');
            }
            if ($run['status'] === 'posted') {
                return;
            }
            $entryDate = $run['run_month'] ?: date('Y-m-01');
            if ($this->periodService->isLocked($entryDate)) {
                throw new Exception('Cannot post depreciation to locked period (' . $entryDate . ')');
            }
            if (!empty($run['journal_id'])) {
                $this->supersedeJournal((int)$run['journal_id'],
                    'Depreciation run #' . $runId . ' re-posted', $entryDate);
            }

            $stmt = $this->db->prepare(
                "SELECT l.amount_cents, a.depreciation_expense_account_id, a.accumulated_depreciation_account_id
                   FROM fa_depreciation_lines l
                   JOIN gl_fixed_assets a ON l.asset_id = a.asset_id
                  WHERE l.run_id = ?"
            );
            $stmt->execute([$runId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                throw new Exception('Depreciation run has no lines');
            }
            $expense = [];
            $accum = [];
            foreach ($rows as $row) {
                $expCode = $this->accounts->getById((int)$row['depreciation_expense_account_id']);
                $accCode = $this->accounts->getById((int)$row['accumulated_depreciation_account_id']);
                if (!$expCode || !$accCode) {
                    throw new Exception('Fixed asset missing depreciation account mapping');
                }
                $expense[$expCode] = ($expense[$expCode] ?? 0) + (int)$row['amount_cents'];
                $accum[$accCode] = ($accum[$accCode] ?? 0) + (int)$row['amount_cents'];
            }
            $lines = [];
            foreach ($expense as $code => $cents) {
                $lines[] = ['account_code' => $code, 'description' => 'Depreciation Expense',
                    'debit' => $cents / 100, 'credit' => 0];
            }
            foreach ($accum as $code => $cents) {
                $lines[] = ['account_code' => $code, 'description' => 'Accumulated Depreciation',
                    'debit' => 0, 'credit' => $cents / 100];
            }

            $journalId = $this->insertJournal([
                'entry_date'  => $entryDate,
                'reference'   => 'DEP' . $runId,
                'description' => 'Depreciation Run ' . $entryDate,
                'ref_type'    => 'depreciation',
                'ref_id'      => $runId,
                'source_type' => 'manual',
                'source_id'   => $runId,
            ], $lines);

            $this->db->prepare(
                "UPDATE fa_depreciation_runs SET journal_id = ?, status = 'posted' WHERE id = ? AND company_id = ?"
            )->execute([$journalId, $runId, $this->companyId]);
        });
    }

    // ------------------------------------------------------------------
    // AP bills
    // ------------------------------------------------------------------

    /**
     * Post an AP bill. Paid bills cannot be re-posted; posted bills require
     * an explicit $allowRepost (guards against accidental double-posting,
     * which previously also double-received stock).
     */
    public function postApBill(int $billId, bool $allowRepost = false): void
    {
        $this->withTransaction(function () use ($billId, $allowRepost) {
            $stmt = $this->db->prepare(
                "SELECT id, supplier_id, issue_date, vendor_invoice_number, subtotal, tax, total, journal_id, status
                   FROM ap_bills WHERE id = ? AND company_id = ? LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$billId, $this->companyId]);
            $bill = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$bill) {
                throw new Exception('AP bill not found');
            }
            if ($bill['status'] === 'paid') {
                throw new Exception('Bill is already paid — reposting a paid bill is not allowed');
            }
            if ($bill['status'] === 'blocked') {
                throw new Exception('Bill is blocked and cannot be posted — unblock it first');
            }
            if ($bill['status'] === 'posted' && !$allowRepost) {
                throw new Exception('Bill is already posted — pass repost=true to supersede the existing journal');
            }
            $entryDate = $bill['issue_date'] ?? date('Y-m-d');
            if ($this->periodService->isLocked($entryDate)) {
                throw new Exception('Cannot post AP bill to locked period (' . $entryDate . ')');
            }
            if (!empty($bill['journal_id'])) {
                $this->supersedeJournal((int)$bill['journal_id'],
                    'AP bill ' . ($bill['vendor_invoice_number'] ?: $billId) . ' re-posted', $entryDate);
            }

            $stmt = $this->db->prepare(
                "SELECT quantity, unit_price, discount, tax_rate, gl_account_id, project_board_id,
                        project_item_id, item_description, inventory_item_id
                   FROM ap_bill_lines WHERE bill_id = ? ORDER BY sort_order"
            );
            $stmt->execute([$billId]);
            $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$lines) {
                throw new Exception('AP bill has no lines');
            }

            $apCode = $this->accounts->code('finance_ap_account_id');
            $vatInCode = $this->accounts->code('finance_vat_input_account_id');
            $supplierId = (int)$bill['supplier_id'];
            $reference = $bill['vendor_invoice_number'] ?: ('BILL' . $billId);

            $journalLines = [];
            $pendingReceipts = [];
            $totalNetC = 0;
            // VAT grouped by (tax_code_id, capital flag): a bill mixing a
            // fixed-asset line with consumables must split its input VAT
            // between VAT201 capital (Box 7-side) and other goods — a single
            // bill-level flag marked the WHOLE bill's VAT as capital.
            $vatByCode = []; // "taxCodeId|capital" => cents
            foreach ($lines as $li) {
                $qty = (float)$li['quantity'];
                $price = (float)$li['unit_price'];
                $discount = (float)($li['discount'] ?? 0);
                $taxRate = (float)($li['tax_rate'] ?? 0);
                $netC = (int)round(($qty * $price - $discount) * 100);
                $vatC = (int)round($netC * $taxRate / 100);

                $invItemId = !empty($li['inventory_item_id']) ? (int)$li['inventory_item_id'] : null;
                if ($invItemId) {
                    $unitCost = $qty > 0 ? (($netC / 100) / $qty) : 0.0;
                    $pendingReceipts[] = ['item_id' => $invItemId, 'qty' => $qty, 'unit_cost' => $unitCost];
                    $acctCode = $this->accounts->code('finance_inventory_account_id');
                } else {
                    $acctCode = $this->accounts->getById(!empty($li['gl_account_id']) ? (int)$li['gl_account_id'] : null)
                        ?: $this->accounts->code('finance_expense_account_id');
                }

                // Capital goods (VAT201 Box 7): line debits a fixed asset account.
                $stmtChk = $this->db->prepare(
                    "SELECT account_subtype FROM gl_accounts WHERE account_code = ? AND company_id = ? LIMIT 1"
                );
                $stmtChk->execute([$acctCode, $this->companyId]);
                $isCapital = ($stmtChk->fetchColumn() === 'fixed_asset');

                $lineTaxCodeId = $this->resolveInputTaxCodeId($taxRate);
                $journalLines[] = [
                    'account_code' => $acctCode,
                    'description'  => $li['item_description'] ?: ($invItemId ? 'Inventory purchase' : 'AP expense'),
                    'debit'        => $netC / 100,
                    'credit'       => 0,
                    'board_id'     => !empty($li['project_board_id']) ? (int)$li['project_board_id'] : null,
                    'item_id'      => !empty($li['project_item_id']) ? (int)$li['project_item_id'] : null,
                    'supplier_id'  => $supplierId,
                    'tax_code_id'  => $lineTaxCodeId,
                    'is_capital_goods' => $isCapital ? 1 : 0,
                ];
                $totalNetC += $netC;
                if ($vatC !== 0) {
                    $key = ($lineTaxCodeId ?? 0) . '|' . ($isCapital ? 1 : 0);
                    $vatByCode[$key] = ($vatByCode[$key] ?? 0) + $vatC;
                }
            }

            $totalVatC = array_sum($vatByCode);
            foreach ($vatByCode as $key => $vatC) {
                [$taxCodeId, $capital] = explode('|', $key);
                $journalLines[] = [
                    'account_code' => $vatInCode,
                    'description'  => 'VAT Input - ' . $reference,
                    'debit'        => $vatC / 100,
                    'credit'       => 0,
                    'supplier_id'  => $supplierId,
                    'tax_code_id'  => (int)$taxCodeId ?: null,
                    'is_capital_goods' => (int)$capital,
                ];
            }
            $journalLines[] = [
                'account_code' => $apCode,
                'description'  => 'Accounts Payable - ' . ($bill['vendor_invoice_number'] ?: ''),
                'debit'        => 0,
                'credit'       => ($totalNetC + $totalVatC) / 100,
                'supplier_id'  => $supplierId,
            ];

            $movementIds = [];
            foreach ($pendingReceipts as $pr) {
                $this->inventory->receive($pr['item_id'], $pr['qty'], $pr['unit_cost'], $entryDate, 'ap_bill', $billId);
                $movementIds[] = (int)$this->db->lastInsertId();
            }

            $journalId = $this->insertJournal([
                'entry_date'  => $entryDate,
                'reference'   => $reference,
                'description' => 'AP Bill ' . $reference,
                'ref_type'    => 'ap_bill',
                'ref_id'      => $billId,
                'source_type' => 'ap_bill',
                'source_id'   => $billId,
            ], $journalLines);

            if ($movementIds) {
                $ph = implode(',', array_fill(0, count($movementIds), '?'));
                $this->db->prepare("UPDATE inventory_movements SET journal_id = ? WHERE id IN ($ph)")
                    ->execute(array_merge([$journalId], $movementIds));
            }

            $oldStatus = $bill['status'];
            $this->db->prepare(
                "UPDATE ap_bills SET journal_id = ?, status = 'posted' WHERE id = ? AND company_id = ?"
            )->execute([$journalId, $billId, $this->companyId]);

            Audit::log('ap_bill_status_change', [
                'bill_id' => $billId,
                'from' => $oldStatus,
                'to' => 'posted',
                'journal_id' => $journalId,
            ], 'ap_bill', $billId, $this->companyId, $this->userId);
        });
    }

    // ------------------------------------------------------------------
    // Supplier payments (AP)
    // ------------------------------------------------------------------

    public function postSupplierPayment(int $paymentId): void
    {
        $this->withTransaction(function () use ($paymentId) {
            $stmt = $this->db->prepare(
                "SELECT * FROM ap_payments WHERE id = ? AND company_id = ? LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$paymentId, $this->companyId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$payment) {
                throw new Exception('AP payment not found');
            }
            $entryDate = $payment['payment_date'] ?? date('Y-m-d');
            if ($this->periodService->isLocked($entryDate)) {
                throw new Exception('Cannot post supplier payment to locked period (' . $entryDate . ')');
            }
            if (!empty($payment['journal_id'])) {
                $this->supersedeJournal((int)$payment['journal_id'],
                    'Supplier payment #' . $paymentId . ' re-posted', $entryDate);
            }

            $stmt = $this->db->prepare(
                "SELECT apa.amount, apa.bill_id, b.supplier_id, b.vendor_invoice_number
                   FROM ap_payment_allocations apa
                   JOIN ap_bills b ON b.id = apa.bill_id
                  WHERE apa.ap_payment_id = ?"
            );
            $stmt->execute([$paymentId]);
            $allocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$allocs) {
                throw new Exception('Supplier payment has no allocations');
            }

            $bankCode = $this->bankCodeFor($payment['bank_account_id'] ?? null);
            $apCode = $this->accounts->code('finance_ap_account_id');
            $reference = $payment['reference'] ?: ('APPAY' . $paymentId);

            $journalLines = [];
            $total = 0.0;
            foreach ($allocs as $al) {
                $amt = round((float)$al['amount'], 2);
                $total += $amt;
                $journalLines[] = [
                    'account_code' => $apCode,
                    'description'  => 'Accounts Payable',
                    'debit'        => $amt,
                    'credit'       => 0,
                    'supplier_id'  => $al['supplier_id'],
                    'reference'    => $al['vendor_invoice_number'] ?: $reference,
                ];
            }
            array_unshift($journalLines, [
                'account_code' => $bankCode,
                'description'  => 'Bank',
                'debit'        => 0,
                'credit'       => round($total, 2),
                'supplier_id'  => $payment['supplier_id'] ?: null,
                'reference'    => $reference,
            ]);

            $journalId = $this->insertJournal([
                'entry_date'  => $entryDate,
                'reference'   => $reference,
                'description' => 'Supplier Payment',
                'ref_type'    => 'ap_payment',
                'ref_id'      => $paymentId,
                'source_type' => 'ap_payment',
                'source_id'   => $paymentId,
            ], $journalLines);

            $this->db->prepare("UPDATE ap_payments SET journal_id = ? WHERE id = ? AND company_id = ?")
                ->execute([$journalId, $paymentId, $this->companyId]);

            // Settle bill statuses (paid / part-paid)
            $billIds = array_unique(array_map(fn($al) => (int)$al['bill_id'], $allocs));
            foreach ($billIds as $bId) {
                $stmtBal = $this->db->prepare(
                    "SELECT total,
                            COALESCE((SELECT SUM(amount) FROM ap_payment_allocations WHERE bill_id = ?),0) AS paid,
                            COALESCE((SELECT SUM(amount) FROM vendor_credit_allocations WHERE bill_id = ?),0) AS credited
                       FROM ap_bills WHERE id = ? AND company_id = ?"
                );
                $stmtBal->execute([$bId, $bId, $bId, $this->companyId]);
                $row = $stmtBal->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $due = (float)$row['total'] - ((float)$row['paid'] + (float)$row['credited']);
                    if ($due <= 0.0001) {
                        $this->db->prepare("UPDATE ap_bills SET status = 'paid' WHERE id = ? AND company_id = ?")
                            ->execute([$bId, $this->companyId]);
                        Audit::log('ap_bill_status_change', [
                            'bill_id' => $bId, 'from' => 'posted', 'to' => 'paid', 'payment_id' => $paymentId,
                        ], 'ap_bill', $bId, $this->companyId, $this->userId);
                    }
                }
            }
        });
    }

    // ------------------------------------------------------------------
    // Vendor credits (AP)
    // ------------------------------------------------------------------

    public function postVendorCredit(int $creditId): void
    {
        $this->withTransaction(function () use ($creditId) {
            $stmt = $this->db->prepare(
                "SELECT * FROM vendor_credits WHERE id = ? AND company_id = ? LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$creditId, $this->companyId]);
            $credit = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$credit) {
                throw new Exception('Vendor credit not found');
            }
            $entryDate = $credit['issue_date'] ?? date('Y-m-d');
            if ($this->periodService->isLocked($entryDate)) {
                throw new Exception('Cannot post vendor credit to locked period (' . $entryDate . ')');
            }
            if (!empty($credit['journal_id'])) {
                $this->supersedeJournal((int)$credit['journal_id'],
                    'Vendor credit ' . $credit['credit_number'] . ' re-posted', $entryDate);
            }

            $stmt = $this->db->prepare(
                "SELECT quantity, unit_price, discount, tax_rate, gl_account_id, item_description
                   FROM vendor_credit_lines WHERE credit_id = ? ORDER BY sort_order"
            );
            $stmt->execute([$creditId]);
            $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$lines) {
                throw new Exception('Vendor credit has no lines');
            }

            $apCode = $this->accounts->code('finance_ap_account_id');
            $vatInCode = $this->accounts->code('finance_vat_input_account_id');
            $supplierId = (int)$credit['supplier_id'];
            $reference = $credit['credit_number'];

            $journalLines = [];
            $totalNetC = 0;
            // Grouped by (tax_code_id, capital flag) like postApBill: a credit
            // that reverses a capital purchase must reduce CAPITAL input VAT
            // (Box 7 side), not "other goods" — otherwise the VAT201 7/8 split
            // is wrong in both directions after a credit.
            $vatByCode = []; // "taxCodeId|capital" => cents
            foreach ($lines as $li) {
                $netC = (int)round(((float)$li['quantity'] * (float)$li['unit_price'] - (float)($li['discount'] ?? 0)) * 100);
                $rate = (float)($li['tax_rate'] ?? 0);
                $vatC = (int)round($netC * $rate / 100);
                $acctCode = $this->accounts->getById(!empty($li['gl_account_id']) ? (int)$li['gl_account_id'] : null)
                    ?: $this->accounts->code('finance_expense_account_id');
                $stmtChk = $this->db->prepare(
                    "SELECT account_subtype FROM gl_accounts WHERE account_code = ? AND company_id = ? LIMIT 1"
                );
                $stmtChk->execute([$acctCode, $this->companyId]);
                $isCapital = ($stmtChk->fetchColumn() === 'fixed_asset');
                $lineTaxCodeId = $this->resolveInputTaxCodeId($rate);
                $journalLines[] = [
                    'account_code' => $acctCode,
                    'description'  => $li['item_description'] ?: 'Vendor credit',
                    'debit'        => 0,
                    'credit'       => $netC / 100,
                    'supplier_id'  => $supplierId,
                    'reference'    => $reference,
                    'tax_code_id'  => $lineTaxCodeId,
                    'is_capital_goods' => $isCapital ? 1 : 0,
                ];
                $totalNetC += $netC;
                if ($vatC !== 0) {
                    $key = ($lineTaxCodeId ?? 0) . '|' . ($isCapital ? 1 : 0);
                    $vatByCode[$key] = ($vatByCode[$key] ?? 0) + $vatC;
                }
            }
            $totalVatC = array_sum($vatByCode);
            foreach ($vatByCode as $key => $vatC) {
                [$taxCodeId, $capital] = explode('|', $key);
                $journalLines[] = [
                    'account_code' => $vatInCode,
                    'description'  => 'VAT Input (Vendor Credit)',
                    'debit'        => 0,
                    'credit'       => $vatC / 100,
                    'supplier_id'  => $supplierId,
                    'reference'    => $reference,
                    'tax_code_id'  => (int)$taxCodeId ?: null,
                    'is_capital_goods' => (int)$capital,
                ];
            }
            $journalLines[] = [
                'account_code' => $apCode,
                'description'  => 'Accounts Payable',
                'debit'        => ($totalNetC + $totalVatC) / 100,
                'credit'       => 0,
                'supplier_id'  => $supplierId,
                'reference'    => $reference,
            ];

            $journalId = $this->insertJournal([
                'entry_date'  => $entryDate,
                'reference'   => $reference,
                'description' => 'Vendor Credit ' . $reference,
                'ref_type'    => 'vendor_credit',
                'ref_id'      => $creditId,
                'source_type' => 'vendor_credit',
                'source_id'   => $creditId,
            ], $journalLines);

            $oldStatus = $credit['status'];
            $this->db->prepare(
                "UPDATE vendor_credits SET journal_id = ?, status = 'applied' WHERE id = ? AND company_id = ?"
            )->execute([$journalId, $creditId, $this->companyId]);

            Audit::log('vendor_credit_status_change', [
                'credit_id' => $creditId, 'from' => $oldStatus, 'to' => 'applied', 'journal_id' => $journalId,
            ], 'vendor_credit', $creditId, $this->companyId, $this->userId);
        });
    }

    // ------------------------------------------------------------------
    // VAT adjustment (return filing)
    // ------------------------------------------------------------------

    public function postVatAdjustment(float $vatOutputTotal, float $vatInputTotal, string $entryDate, string $reference = '', ?int $existingJournalId = null): int
    {
        return $this->withTransaction(function () use ($vatOutputTotal, $vatInputTotal, $entryDate, $reference, $existingJournalId) {
            if ($this->periodService->isLocked($entryDate)) {
                throw new Exception('Cannot post VAT adjustment to locked period (' . $entryDate . ')');
            }
            if ($existingJournalId) {
                $this->supersedeJournal($existingJournalId, 'VAT adjustment re-posted', $entryDate);
            }
            $vatOutCode  = $this->accounts->code('finance_vat_output_account_id');
            $vatInCode   = $this->accounts->code('finance_vat_input_account_id');
            $vatCtrlCode = $this->accounts->code('finance_vat_control_account_id');
            $netVat = round($vatOutputTotal - $vatInputTotal, 2);

            $lines = [];
            if ($vatOutputTotal > 0.0001) {
                $lines[] = ['account_code' => $vatOutCode, 'description' => 'VAT Output Cleared',
                    'debit' => $vatOutputTotal, 'credit' => 0];
            }
            if ($vatInputTotal > 0.0001) {
                $lines[] = ['account_code' => $vatInCode, 'description' => 'VAT Input Cleared',
                    'debit' => 0, 'credit' => $vatInputTotal];
            }
            if ($netVat > 0.0001) {
                $lines[] = ['account_code' => $vatCtrlCode, 'description' => 'VAT Payable',
                    'debit' => 0, 'credit' => $netVat];
            } elseif ($netVat < -0.0001) {
                $lines[] = ['account_code' => $vatCtrlCode, 'description' => 'VAT Receivable',
                    'debit' => abs($netVat), 'credit' => 0];
            }

            // module 'vat_settle': this CLEARING journal (output/input →
            // control) must be invisible to the VAT201 measurement — under
            // the default module 'fin' it would subtract the whole prior
            // period's output/input from whichever period contains its date.
            return $this->insertJournal([
                'entry_date'  => $entryDate,
                'reference'   => $reference ?: 'VAT-ADJ',
                'description' => 'VAT Adjustment ' . $reference,
                'module'      => 'vat_settle',
                'ref_type'    => 'vat_adjustment',
                'ref_id'      => null,
                'source_type' => 'vat_adjustment',
                'source_id'   => null,
            ], $lines);
        });
    }
}
