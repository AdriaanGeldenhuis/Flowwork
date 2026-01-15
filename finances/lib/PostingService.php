<?php
// finances/lib/PostingService.php
//
// PostingService centralises the creation of accounting journal entries for
// various transactions (invoices, payments, bills, etc.). It relies on
// AccountsMap to resolve the correct GL accounts from company settings and
// PeriodService to ensure that postings do not occur in locked periods.

require_once __DIR__ . '/AccountsMap.php';
require_once __DIR__ . '/PeriodService.php';
require_once __DIR__ . '/InventoryService.php';
require_once __DIR__ . '/ReversalService.php';

class PostingService
{
    private $db;
    private $companyId;
    private $userId;
    private $accounts;
    private $periodService;
    private $inventory;

    /**
     * Constructor
     *
     * @param PDO $db The PDO instance
     * @param int $companyId The current company id
     * @param int $userId The id of the user performing the posting
     */
    public function __construct(PDO $db, int $companyId, int $userId)
    {
        $this->db = $db;
        $this->companyId = $companyId;
        $this->userId = $userId;
        $this->accounts = new AccountsMap($db, $companyId);
        $this->periodService = new PeriodService($db, $companyId);
        // Initialise inventory service for stock movements
        $this->inventory = new InventoryService($db, $companyId);
    }

    /**
     * Remove an existing journal entry by id. This helper deletes the entry
     * and its associated lines. Use this prior to re-posting a transaction
     * that has already been posted. If the period of the entry is locked
     * the deletion will be skipped silently.
     *
     * @param int $journalId
     * @return void
     */
    private function deleteJournal(int $journalId): void
    {
        if (!$journalId) {
            return;
        }
        // Fetch the journal's entry date to check period lock
        $stmt = $this->db->prepare("SELECT entry_date FROM journal_entries WHERE id = ? AND company_id = ?");
        $stmt->execute([$journalId, $this->companyId]);
        $date = $stmt->fetchColumn();
        if (!$date || $this->periodService->isLocked($date)) {
            // Period is locked or journal not found; do not delete
            return;
        }
        // Delete lines then entry
        $stmt = $this->db->prepare("DELETE FROM journal_lines WHERE journal_id = ?");
        $stmt->execute([$journalId]);
        $stmt = $this->db->prepare("DELETE FROM journal_entries WHERE id = ? AND company_id = ?");
        $stmt->execute([$journalId, $this->companyId]);
    }

    /**
     * Post an invoice to the general ledger. This method calculates net sales
     * and VAT per invoice line and creates a balanced journal: debit AR,
     * credit Sales and VAT. If the invoice already has a journal it will
     * be removed and re-posted. Invoices dated in locked periods cannot be
     * posted.
     *
     * @param int $invoiceId
     * @throws Exception on locked period or missing defaults
     */
    public function postInvoice(int $invoiceId): void
    {
        // Fetch invoice
        $stmt = $this->db->prepare(
            "SELECT * FROM invoices WHERE id = ? AND company_id = ? LIMIT 1"
        );
        $stmt->execute([$invoiceId, $this->companyId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new Exception('Invoice not found');
        }
        // Check period lock
        $entryDate = $invoice['issue_date'] ?? date('Y-m-d');
        if ($this->periodService->isLocked($entryDate)) {
            throw new Exception('Cannot post to locked period (' . $entryDate . ')');
        }
        // Remove existing journal if present
        if (!empty($invoice['journal_id'])) {
            $this->deleteJournal((int)$invoice['journal_id']);
        }
        // Fetch invoice lines (including optional inventory item reference)
        $stmt = $this->db->prepare(
            "SELECT quantity, unit_price, discount, tax_rate, gl_account_id, inventory_item_id FROM invoice_lines WHERE invoice_id = ?"
        );
        $stmt->execute([$invoiceId]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$lines) {
            throw new Exception('Invoice has no lines');
        }
        // Determine default accounts
        $arCode    = $this->accounts->get('finance_ar_account_id', '1200');
        $salesDef  = $this->accounts->get('finance_sales_account_id', '4100');
        $vatCode   = $this->accounts->get('finance_vat_output_account_id', '2120');
        // Accounts for inventory and cost of goods sold
        $inventoryCode = $this->accounts->get('finance_inventory_account_id', '1300');
        $cogsCode      = $this->accounts->get('finance_cogs_account_id', '5000');
        if (!$arCode || !$salesDef) {
            throw new Exception('Finance settings incomplete (AR or Sales)');
        }
        // Aggregate net sales, VAT, and inventory cost
        $salesTotals = [];
        $totalVat    = 0.0;
        $cogsTotals  = [];
        $invTotals   = [];
        foreach ($lines as $li) {
            $qty      = floatval($li['quantity']);
            $price    = floatval($li['unit_price']);
            $discount = floatval($li['discount']);
            $taxRate  = floatval($li['tax_rate']);
            $net      = ($qty * $price) - $discount;
            $vat      = $net * ($taxRate / 100);
            // Determine sales account
            $code = $this->accounts->getById($li['gl_account_id']) ?: $salesDef;
            if (!isset($salesTotals[$code])) {
                $salesTotals[$code] = 0.0;
            }
            $salesTotals[$code] += $net;
            $totalVat += $vat;
            // If line relates to a stocked inventory item, record COGS/inventory movements
            $invItemId = isset($li['inventory_item_id']) && $li['inventory_item_id'] ? (int)$li['inventory_item_id'] : null;
            if ($invItemId && $qty > 0) {
                // Use invoice issue date if available
                $invDate = $invoice['issue_date'] ?? date('Y-m-d');
                $cost    = $this->inventory->issue($invItemId, $qty, $invDate, 'invoice', $invoiceId);
                if ($cost > 0.0001) {
                    if (!isset($cogsTotals[$cogsCode])) {
                        $cogsTotals[$cogsCode] = 0.0;
                    }
                    if (!isset($invTotals[$inventoryCode])) {
                        $invTotals[$inventoryCode] = 0.0;
                    }
                    $cogsTotals[$cogsCode] += $cost;
                    $invTotals[$inventoryCode] += $cost;
                }
            }
        }
        $total = array_sum($salesTotals) + $totalVat;
        // Post journal
        $this->db->beginTransaction();
        try {
            // Insert journal entry
            $stmt = $this->db->prepare(
                "INSERT INTO journal_entries (
                    company_id, entry_date, reference, description, module, ref_type, ref_id,
                    source_type, source_id, created_by, created_at
                ) VALUES (?, ?, ?, ?, 'fin', 'invoice', ?, 'invoice', ?, ?, NOW())"
            );
            $reference = $invoice['invoice_number'];
            $desc      = 'Invoice ' . $reference;
            $stmt->execute([
                $this->companyId,
                $entryDate,
                $reference,
                $desc,
                $invoiceId,
                $invoiceId,
                $this->userId
            ]);
            $journalId = (int)$this->db->lastInsertId();
            // Insert AR debit line
            $stmtLine = $this->db->prepare(
                "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, customer_id, reference)
                 VALUES (?, ?, ?, ?, 0, ?, ?)"
            );
            $stmtLine->execute([
                $journalId,
                $arCode,
                'Accounts Receivable',
                number_format($total, 2, '.', ''),
                $invoice['customer_id'],
                $reference
            ]);
            // Insert sales credit lines per account
            $stmtLine = $this->db->prepare(
                "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, customer_id, reference)
                 VALUES (?, ?, ?, 0, ?, ?, ?)"
            );
            foreach ($salesTotals as $code => $amount) {
                $stmtLine->execute([
                    $journalId,
                    $code,
                    'Sales Income',
                    number_format($amount, 2, '.', ''),
                    $invoice['customer_id'],
                    $reference
                ]);
            }
            // Insert VAT credit line if VAT > 0
            if ($totalVat > 0.0001) {
                $stmtLine->execute([
                    $journalId,
                    $vatCode,
                    'VAT Output',
                    number_format($totalVat, 2, '.', ''),
                    $invoice['customer_id'],
                    $reference
                ]);
            }
            // Insert cost of goods sold and inventory lines if applicable
            if (!empty($cogsTotals)) {
                // Debit COGS (expense) per aggregated amount
                $stmtCogs = $this->db->prepare(
                    "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, customer_id, reference) "
                    . "VALUES (?, ?, ?, ?, 0, ?, ?)"
                );
                foreach ($cogsTotals as $code => $amount) {
                    $stmtCogs->execute([
                        $journalId,
                        $code,
                        'Cost of Goods Sold',
                        number_format($amount, 2, '.', ''),
                        $invoice['customer_id'],
                        $reference
                    ]);
                }
                // Credit Inventory account for the same amounts
                $stmtInv = $this->db->prepare(
                    "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, customer_id, reference) "
                    . "VALUES (?, ?, ?, 0, ?, ?, ?)"
                );
                foreach ($invTotals as $code => $amount) {
                    $stmtInv->execute([
                        $journalId,
                        $code,
                        'Inventory',
                        number_format($amount, 2, '.', ''),
                        $invoice['customer_id'],
                        $reference
                    ]);
                }
            }
            // Update invoice with journal id
            $stmt = $this->db->prepare("UPDATE invoices SET journal_id = ? WHERE id = ? AND company_id = ?");
            $stmt->execute([$journalId, $invoiceId, $this->companyId]);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Post a customer payment to the general ledger. This records a debit to
     * Bank and credits Accounts Receivable per allocation. If the payment
     * already has a journal it will be replaced. Payments dated in locked
     * periods cannot be posted.
     *
     * @param int $paymentId
     * @return void
     * @throws Exception on locked period
     */
    public function postCustomerPayment(int $paymentId): void
    {
        // Fetch payment
        $stmt = $this->db->prepare(
            "SELECT * FROM payments WHERE id = ? AND company_id = ? LIMIT 1"
        );
        $stmt->execute([$paymentId, $this->companyId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            throw new Exception('Payment not found');
        }
        // Check period lock
        $entryDate = $payment['payment_date'] ?? date('Y-m-d');
        if ($this->periodService->isLocked($entryDate)) {
            throw new Exception('Cannot post payment to locked period (' . $entryDate . ')');
        }
        // Remove existing journal if present
        if (!empty($payment['journal_id'])) {
            $this->deleteJournal((int)$payment['journal_id']);
        }
        // Fetch allocations
        $stmt = $this->db->prepare(
            "SELECT pa.amount, i.customer_id, i.invoice_number FROM payment_allocations pa
             LEFT JOIN invoices i ON pa.invoice_id = i.id
             WHERE pa.payment_id = ?"
        );
        $stmt->execute([$paymentId]);
        $allocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$allocs) {
            throw new Exception('Payment has no allocations');
        }
        // Determine accounts
        // Bank account is chosen from the payment record (bank_account_id). Convert to code.
        $bankCode = null;
        if (!empty($payment['bank_account_id'])) {
            $stmt = $this->db->prepare(
                "SELECT ga.account_code FROM gl_bank_accounts ba
                 JOIN gl_accounts ga ON ga.account_id = ba.gl_account_id
                 WHERE ba.id = ? AND ba.company_id = ? LIMIT 1"
            );
            $stmt->execute([$payment['bank_account_id'], $this->companyId]);
            $bankCode = $stmt->fetchColumn();
        }
        if (!$bankCode) {
            // Fallback to finance_bank_account_id setting or 1110
            $bankCode = $this->accounts->get('finance_bank_account_id', '1110');
        }
        $arCode = $this->accounts->get('finance_ar_account_id', '1200');
        // Compute total amount
        $totalAmt = 0.0;
        foreach ($allocs as $al) {
            $totalAmt += floatval($al['amount']);
        }
        // Post journal
        $this->db->beginTransaction();
        try {
            $reference = $payment['reference'] ?: ('PAY' . $paymentId);
            $desc      = 'Payment';
            // Insert journal entry
            $stmt = $this->db->prepare(
                "INSERT INTO journal_entries (
                    company_id, entry_date, reference, description, module, ref_type, ref_id,
                    source_type, source_id, created_by, created_at
                ) VALUES (?, ?, ?, ?, 'fin', 'payment', ?, 'payment', ?, ?, NOW())"
            );
            $stmt->execute([
                $this->companyId,
                $entryDate,
                $reference,
                $desc,
                $paymentId,
                $paymentId,
                $this->userId
            ]);
            $journalId = (int)$this->db->lastInsertId();
            // Debit bank
            $stmtLine = $this->db->prepare(
                "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, customer_id, reference)
                 VALUES (?, ?, ?, ?, 0, ?, ?)"
            );
            $stmtLine->execute([
                $journalId,
                $bankCode,
                'Bank',
                number_format($totalAmt, 2, '.', ''),
                null,
                $reference
            ]);
            // Credit AR lines
            $stmtLine = $this->db->prepare(
                "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, customer_id, reference)
                 VALUES (?, ?, ?, 0, ?, ?, ?)"
            );
            foreach ($allocs as $al) {
                $amt  = floatval($al['amount']);
                $cust = $al['customer_id'] ?: null;
                $ref  = $al['invoice_number'] ?: $reference;
                $stmtLine->execute([
                    $journalId,
                    $arCode,
                    'Accounts Receivable',
                    number_format($amt, 2, '.', ''),
                    $cust,
                    $ref
                ]);
            }
            // Update payment with journal id
            $stmt = $this->db->prepare(
                "UPDATE payments SET journal_id = ? WHERE id = ? AND company_id = ?"
            );
            $stmt->execute([$journalId, $paymentId, $this->companyId]);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Post a credit note to the general ledger. A credit note reverses part or all
     * of a sale. It debits Sales (reducing revenue) and VAT Output (reducing
     * the VAT liability) and credits Accounts Receivable for the total amount of
     * the credit. If a journal entry already exists for this credit note it
     * will be removed first. Credit notes dated in locked periods cannot be
     * posted. If the credit note has no lines, an exception is thrown.
     *
     * @param int $creditNoteId
     * @throws Exception on locked period or invalid credit note
     */
    public function postCreditNote(int $creditNoteId): void
    {
        // Fetch the credit note
        $stmt = $this->db->prepare(
            "SELECT cn.*, i.customer_id FROM credit_notes cn
             LEFT JOIN invoices i ON cn.invoice_id = i.id
             WHERE cn.id = ? AND cn.company_id = ? LIMIT 1"
        );
        $stmt->execute([$creditNoteId, $this->companyId]);
        $credit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$credit) {
            throw new Exception('Credit note not found');
        }
        // Determine entry date: use credit note issue date
        $entryDate = $credit['issue_date'] ?? date('Y-m-d');
        if ($this->periodService->isLocked($entryDate)) {
            throw new Exception('Cannot post credit note to locked period (' . $entryDate . ')');
        }
        // Remove existing journal if any
        if (!empty($credit['journal_id'])) {
            $this->deleteJournal((int)$credit['journal_id']);
        }
        // Fetch credit note lines
        $stmt = $this->db->prepare(
            "SELECT quantity, unit_price, discount, tax_rate FROM credit_note_lines WHERE credit_note_id = ?"
        );
        $stmt->execute([$creditNoteId]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$lines) {
            throw new Exception('Credit note has no lines');
        }
        // Resolve account codes
        $arCode   = $this->accounts->get('finance_ar_account_id', '1200');
        $salesDef = $this->accounts->get('finance_sales_account_id', '4100');
        $vatCode  = $this->accounts->get('finance_vat_output_account_id', '2120');
        if (!$arCode || !$salesDef) {
            throw new Exception('Finance settings incomplete (AR or Sales)');
        }
        // Aggregate net and VAT
        $netTotal  = 0.0;
        $vatTotal  = 0.0;
        foreach ($lines as $li) {
            $qty      = floatval($li['quantity']);
            $price    = floatval($li['unit_price']);
            $discount = floatval($li['discount']);
            $taxRate  = floatval($li['tax_rate']);
            $net      = ($qty * $price) - $discount;
            $vat      = $net * ($taxRate / 100);
            $netTotal += $net;
            $vatTotal += $vat;
        }
        $total = $netTotal + $vatTotal;
        // Post journal
        $this->db->beginTransaction();
        try {
            // Insert journal entry; ref_type/source_type set to credit_note
            $stmt = $this->db->prepare(
                "INSERT INTO journal_entries (
                    company_id, entry_date, reference, description, module, ref_type, ref_id,
                    source_type, source_id, created_by, created_at
                ) VALUES (?, ?, ?, ?, 'fin', 'credit_note', ?, 'credit_note', ?, ?, NOW())"
            );
            $reference = $credit['credit_note_number'];
            $desc      = 'Credit Note ' . $reference;
            $stmt->execute([
                $this->companyId,
                $entryDate,
                $reference,
                $desc,
                $creditNoteId,
                $creditNoteId,
                $this->userId
            ]);
            $journalId = (int)$this->db->lastInsertId();
            // Debit Sales (reduce revenue)
            $stmtLine = $this->db->prepare(
                "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, customer_id, reference)
                 VALUES (?, ?, ?, ?, 0, ?, ?)"
            );
            $stmtLine->execute([
                $journalId,
                $salesDef,
                'Sales Return',
                number_format($netTotal, 2, '.', ''),
                $credit['customer_id'] ?: null,
                $reference
            ]);
            // Debit VAT Output (reduce VAT liability) if any
            if ($vatTotal > 0.0001) {
                $stmtLine->execute([
                    $journalId,
                    $vatCode,
                    'VAT Output (Credit Note)',
                    number_format($vatTotal, 2, '.', ''),
                    $credit['customer_id'] ?: null,
                    $reference
                ]);
            }
            // Credit Accounts Receivable
            $stmtLine = $this->db->prepare(
                "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, customer_id, reference)
                 VALUES (?, ?, ?, 0, ?, ?, ?)"
            );
            $stmtLine->execute([
                $journalId,
                $arCode,
                'Accounts Receivable',
                number_format($total, 2, '.', ''),
                $credit['customer_id'] ?: null,
                $reference
            ]);
            // Optionally update credit note with journal id if column exists
            // But we do not assume a journal_id column; rely on journal_entries linkage
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // TODO: implement postApBill, postSupplierPayment, postCreditNote, postVatAdjustment, postDepreciation
    /**
     * Post a payroll run to the general ledger. Each run aggregates gross wages, taxes and
     * contributions per employee and produces a single journal entry. The journal debits
     * wage expense for the total employer cost (gross plus employer UIF and SDL plus
     * reimbursements minus other deductions) and credits bank for net pay, PAYE for
     * employee tax, UIF for combined employee/employer UIF, and SDL for SDL. If a
     * journal already exists for the run it will be removed and re-created. The run
     * must not be in a locked period. Payroll settings must define the GL codes for
     * wage expense, PAYE, UIF and SDL.
     *
     * @param int $runId The payroll run ID
     * @throws Exception if the run is missing, in a locked period or settings incomplete
     */
    public function postPayrollRun(int $runId): void
    {
        // Load run details
        $stmt = $this->db->prepare(
            "SELECT pr.*, ps.auto_post_to_finance, ps.default_wage_gl_code, ps.default_paye_gl_code,
                    ps.default_uif_gl_code, ps.default_sdl_gl_code, pr.journal_id
             FROM pay_runs pr
             LEFT JOIN payroll_settings ps ON ps.company_id = pr.company_id
             WHERE pr.id = ? AND pr.company_id = ? LIMIT 1"
        );
        $stmt->execute([$runId, $this->companyId]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            throw new Exception('Payroll run not found');
        }
        // Determine entry date: use pay_date or current date
        $entryDate = $run['pay_date'] ?: date('Y-m-d');
        // Period lock check
        if ($this->periodService->isLocked($entryDate)) {
            throw new Exception('Cannot post payroll run to locked period (' . $entryDate . ')');
        }
        // Remove existing journal if present
        if (!empty($run['journal_id'])) {
            $this->deleteJournal((int)$run['journal_id']);
        }
        // Load aggregated totals from pay_run_employees
        $stmt = $this->db->prepare(
            "SELECT 
                COALESCE(SUM(gross_cents), 0) AS gross,
                COALESCE(SUM(paye_cents), 0) AS paye,
                COALESCE(SUM(uif_employee_cents), 0) AS uif_emp,
                COALESCE(SUM(uif_employer_cents), 0) AS uif_empr,
                COALESCE(SUM(sdl_cents), 0) AS sdl,
                COALESCE(SUM(other_deductions_cents), 0) AS other_ded,
                COALESCE(SUM(reimbursements_cents), 0) AS reimburse,
                COALESCE(SUM(bank_amount_cents), 0) AS bank
             FROM pay_run_employees
             WHERE run_id = ? AND company_id = ?"
        );
        $stmt->execute([$runId, $this->companyId]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);
        // Convert cents to floats for calculations
        $gross        = floatval($totals['gross']) / 100.0;
        $paye         = floatval($totals['paye']) / 100.0;
        $uifEmp       = floatval($totals['uif_emp']) / 100.0;
        $uifEmpr      = floatval($totals['uif_empr']) / 100.0;
        $sdl          = floatval($totals['sdl']) / 100.0;
        $otherDed     = floatval($totals['other_ded']) / 100.0;
        $reimburse    = floatval($totals['reimburse']) / 100.0;
        $bankTotal    = floatval($totals['bank']) / 100.0;
        // Determine account codes from payroll settings; fallback to defaults
        $wageCode = $run['default_wage_gl_code'] ?: '6000';
        $payeCode = $run['default_paye_gl_code'] ?: '2100';
        $uifCode  = $run['default_uif_gl_code'] ?: '2101';
        $sdlCode  = $run['default_sdl_gl_code'] ?: '2102';
        // Attempt to determine bank account code from gl_bank_accounts; use first active account
        $bankCode = null;
        $stmt = $this->db->prepare(
            "SELECT ga.account_code
             FROM gl_bank_accounts ba
             JOIN gl_accounts ga ON ga.account_id = ba.gl_account_id
             WHERE ba.company_id = ?
             ORDER BY ba.id ASC LIMIT 1"
        );
        $stmt->execute([$this->companyId]);
        $bankCode = $stmt->fetchColumn();
        if (!$bankCode) {
            // Fallback to generic bank code
            $bankCode = '1110';
        }
        // Compute UIF total and total debit (wages expense)
        $uifTotal = $uifEmp + $uifEmpr;
        // Total wages expense: gross + employer contributions + reimbursements - other deductions + SDL
        // The formula ensures debits equal credits: see explanation in documentation.
        $wageDebit = $gross + $uifEmpr + $sdl + $reimburse - $otherDed;
        // Compute credits breakdown
        $credits = [
            $bankCode => $bankTotal,
            $payeCode => $paye,
            $uifCode  => $uifTotal,
            $sdlCode  => $sdl
        ];
        // Remove zero-credit accounts
        foreach ($credits as $code => $amount) {
            if ($amount <= 0.0001) {
                unset($credits[$code]);
            }
        }
        // Only post if there is anything to post
        if ($wageDebit <= 0.0001 && empty($credits)) {
            // Nothing to post
            return;
        }
        // Post journal
        $this->db->beginTransaction();
        try {
            // Insert journal entry
            $stmt = $this->db->prepare(
                "INSERT INTO journal_entries (
                    company_id, entry_date, reference, description, module, ref_type, ref_id,
                    source_type, source_id, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, 'payroll', 'pay_run', ?, 'payroll', ?, ?, NOW()
                )"
            );
            $reference = $run['run_number'] ?: ('PR' . $runId);
            $desc = 'Payroll Run ' . $reference;
            $stmt->execute([
                $this->companyId,
                $entryDate,
                $reference,
                $desc,
                $runId,
                $runId,
                $this->userId
            ]);
            $journalId = (int)$this->db->lastInsertId();
            // Debit wages expense
            $stmtLine = $this->db->prepare(
                "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, employee_id, reference)
                 VALUES (?, ?, ?, ?, 0, NULL, ?)"
            );
            $stmtLine->execute([
                $journalId,
                $wageCode,
                'Payroll Wages Expense',
                number_format($wageDebit, 2, '.', ''),
                $reference
            ]);
            // Credit lines
            $stmtCred = $this->db->prepare(
                "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, employee_id, reference)
                 VALUES (?, ?, ?, 0, ?, NULL, ?)"
            );
            foreach ($credits as $code => $amount) {
                $descLine = '';
                if ($code === $bankCode) {
                    $descLine = 'Payroll Net Pay';
                } elseif ($code === $payeCode) {
                    $descLine = 'PAYE Payable';
                } elseif ($code === $uifCode) {
                    $descLine = 'UIF Payable';
                } elseif ($code === $sdlCode) {
                    $descLine = 'SDL Payable';
                } else {
                    $descLine = 'Payroll Liability';
                }
                $stmtCred->execute([
                    $journalId,
                    $code,
                    $descLine,
                    number_format($amount, 2, '.', ''),
                    $reference
                ]);
            }
            // Update run with journal id
            $stmt = $this->db->prepare(
                "UPDATE pay_runs SET journal_id = ? WHERE id = ? AND company_id = ?"
            );
            $stmt->execute([$journalId, $runId, $this->companyId]);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    /**
     * Post a depreciation run to the general ledger. Each run consists of
     * multiple depreciation lines (one per asset) which specify the amount
     * of depreciation to record for that asset in cents. This method
     * aggregates amounts by expense and accumulated depreciation accounts,
     * creates a single journal entry dated at the run month, inserts
     * appropriate debit (expense) and credit (accumulated) lines, and
     * updates the run status to 'posted' with the journal id. Assets must
     * have depreciation expense and accumulated depreciation account ids
     * defined. If the run or its period is locked, an exception is thrown.
     *
     * @param int $runId The id of the depreciation run
     * @throws Exception on locked period or missing data
     */
    public function postDepreciation(int $runId): void
    {
        // Fetch run
        $stmt = $this->db->prepare(
            "SELECT id, company_id, run_month, status, journal_id FROM fa_depreciation_runs WHERE id = ? AND company_id = ?"
        );
        $stmt->execute([$runId, $this->companyId]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            throw new Exception('Depreciation run not found');
        }
        if ($run['status'] === 'posted') {
            // Already posted; nothing to do
            return;
        }
        // Determine entry date from run_month (store date string)
        $entryDate = $run['run_month'] ?: date('Y-m-01');
        // Validate unlocked period
        if ($this->periodService->isLocked($entryDate)) {
            throw new Exception('Cannot post depreciation to locked period (' . $entryDate . ')');
        }
        // Remove existing journal if present (possible re-post)
        if (!empty($run['journal_id'])) {
            $this->deleteJournal((int)$run['journal_id']);
        }
        // Fetch depreciation lines with associated asset accounts
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
        // Aggregate amounts by expense and accumulated accounts
        $expenseTotals = [];
        $accumTotals   = [];
        foreach ($rows as $row) {
            $cents = (int)$row['amount_cents'];
            // Convert to decimal for journal entry; use 2 decimals
            $amount = $cents / 100.0;
            $expId  = (int)$row['depreciation_expense_account_id'];
            $accId  = (int)$row['accumulated_depreciation_account_id'];
            // Resolve account codes
            $expCode = $this->accounts->getById($expId);
            $accCode = $this->accounts->getById($accId);
            if (!$expCode || !$accCode) {
                throw new Exception('Fixed asset missing depreciation account mapping');
            }
            if (!isset($expenseTotals[$expCode])) {
                $expenseTotals[$expCode] = 0.0;
            }
            $expenseTotals[$expCode] += $amount;
            if (!isset($accumTotals[$accCode])) {
                $accumTotals[$accCode] = 0.0;
            }
            $accumTotals[$accCode] += $amount;
        }
        // Build journal entry
        $this->db->beginTransaction();
        try {
            $reference = 'DEP' . $runId;
            $desc      = 'Depreciation Run ' . $entryDate;
            $stmtJ = $this->db->prepare(
                "INSERT INTO journal_entries (
                    company_id, entry_date, reference, description,
                    module, ref_type, ref_id, source_type, source_id,
                    created_by, created_at
                ) VALUES (?, ?, ?, ?, 'fin', 'depreciation', ?, 'depreciation', ?, ?, NOW())"
            );
            $stmtJ->execute([
                $this->companyId,
                $entryDate,
                $reference,
                $desc,
                $runId,
                $runId,
                $this->userId
            ]);
            $journalId = (int)$this->db->lastInsertId();
            // Insert debit (expense) lines
            $stmtL1 = $this->db->prepare(
                "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit)
                 VALUES (?, ?, ?, ?, 0)"
            );
            foreach ($expenseTotals as $code => $amt) {
                $stmtL1->execute([
                    $journalId,
                    $code,
                    'Depreciation Expense',
                    number_format($amt, 2, '.', '')
                ]);
            }
            // Insert credit (accumulated) lines
            $stmtL2 = $this->db->prepare(
                "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit)
                 VALUES (?, ?, ?, 0, ?)"
            );
            foreach ($accumTotals as $code => $amt) {
                $stmtL2->execute([
                    $journalId,
                    $code,
                    'Accumulated Depreciation',
                    number_format($amt, 2, '.', '')
                ]);
            }
            // Update run status and journal id
            $stmtU = $this->db->prepare(
                "UPDATE fa_depreciation_runs SET journal_id = ?, status = 'posted' WHERE id = ? AND company_id = ?"
            );
            $stmtU->execute([
                $journalId,
                $runId,
                $this->companyId
            ]);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Post an AP bill to the general ledger. This routine looks up the
     * bill header and its lines, determines the appropriate GL accounts
     * based on company settings, and creates a balanced journal entry. It
     * debits expense (or inventory) accounts and VAT Input, and credits
     * Accounts Payable for the gross amount. If a journal already exists
     * for this bill it will be removed first. Bills dated in a locked
     * period cannot be posted.
     *
     * @param int $billId The ID of the AP bill
     * @throws Exception on locked period or missing bill
     */
    public function postApBill(int $billId): void
    {
        // Load bill header
        $stmt = $this->db->prepare(
            "SELECT id, supplier_id, issue_date, vendor_invoice_number, subtotal, tax, total, journal_id, status\n"
            . "FROM ap_bills WHERE id = ? AND company_id = ? LIMIT 1"
        );
        $stmt->execute([$billId, $this->companyId]);
        $bill = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$bill) {
            throw new Exception('AP bill not found');
        }
        $entryDate = $bill['issue_date'] ?? date('Y-m-d');
        // Prevent posting into locked period
        if ($this->periodService->isLocked($entryDate)) {
            throw new Exception('Cannot post AP bill to locked period (' . $entryDate . ')');
        }
        // Remove existing journal if present
        if (!empty($bill['journal_id'])) {
            $this->deleteJournal((int)$bill['journal_id']);
        }
        // Fetch bill lines including inventory_item_id
        $stmt = $this->db->prepare(
            "SELECT quantity, unit_price, discount, tax_rate, gl_account_id, project_board_id, project_item_id, item_description, inventory_item_id\n"
            . "FROM ap_bill_lines WHERE bill_id = ? ORDER BY sort_order"
        );
        $stmt->execute([$billId]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$lines) {
            throw new Exception('AP bill has no lines');
        }
        // Determine account codes
        $apCode = $this->accounts->get('finance_ap_account_id', '2110');
        $vatInCode = $this->accounts->get('finance_vat_input_account_id', '2130');
        // Loop lines, compute net and VAT per line and build journal lines
        $journalLines = [];
        $totalNet = 0.0;
        $totalVat = 0.0;
        foreach ($lines as $li) {
            $qty      = floatval($li['quantity']);
            $price    = floatval($li['unit_price']);
            $discount = isset($li['discount']) ? floatval($li['discount']) : 0.0;
            $taxRate  = isset($li['tax_rate']) ? floatval($li['tax_rate']) : 0.0;
            $net      = ($qty * $price) - $discount;
            $vat      = ($taxRate > 0) ? $net * ($taxRate / 100.0) : 0.0;

            // Determine if this line is a stock receipt. If inventory_item_id is
            // provided, debit the inventory account instead of an expense and
            // record a movement. Otherwise debit expense.
            $invItemId = isset($li['inventory_item_id']) && $li['inventory_item_id'] ? (int)$li['inventory_item_id'] : null;
            if ($invItemId) {
                // Compute unit cost (net per unit) and record receipt
                $unitCost = $qty > 0 ? ($net / $qty) : 0.0;
                $this->inventory->receive($invItemId, $qty, $unitCost, $entryDate, 'ap_bill', $billId);
                // Use inventory account
                $acctCode = $this->accounts->get('finance_inventory_account_id', '1300');
            } else {
                // Use GL account specified on line or fallback to expense
                $acctCode = null;
                if (!empty($li['gl_account_id'])) {
                    $acctCode = $this->accounts->getById((int)$li['gl_account_id']);
                }
                if (!$acctCode) {
                    $acctCode = $this->accounts->get('finance_expense_account_id', '5000');
                }
            }
            $journalLines[] = [
                'account_code' => $acctCode,
                'description'  => $li['item_description'] ?: ($invItemId ? 'Inventory purchase' : 'AP expense'),
                'debit'        => $net,
                'credit'       => 0.0,
                'project_id'   => null,
                'board_id'     => !empty($li['project_board_id']) ? (int)$li['project_board_id'] : null,
                'item_id'      => !empty($li['project_item_id']) ? (int)$li['project_item_id'] : null,
                'supplier_id'  => (int)$bill['supplier_id']
            ];
            $totalNet += $net;
            $totalVat += $vat;
        }
        // VAT Input line if applicable
        if ($totalVat > 0.0001) {
            $journalLines[] = [
                'account_code' => $vatInCode,
                'description'  => 'VAT Input - ' . ($bill['vendor_invoice_number'] ?: 'AP Bill'),
                'debit'        => $totalVat,
                'credit'       => 0.0,
                'project_id'   => null,
                'board_id'     => null,
                'item_id'      => null,
                'supplier_id'  => (int)$bill['supplier_id']
            ];
        }
        // Credit AP for gross (net + VAT)
        $gross = $totalNet + $totalVat;
        $journalLines[] = [
            'account_code' => $apCode,
            'description'  => 'Accounts Payable - ' . ($bill['vendor_invoice_number'] ?: ''),
            'debit'        => 0.0,
            'credit'       => $gross,
            'project_id'   => null,
            'board_id'     => null,
            'item_id'      => null,
            'supplier_id'  => (int)$bill['supplier_id']
        ];
        // Now post journal
        $this->db->beginTransaction();
        try {
            // Create journal entry
            $stmt = $this->db->prepare(
                "INSERT INTO journal_entries (company_id, entry_date, reference, description, module, ref_type, ref_id,\n"
                . "source_type, source_id, created_by, created_at)\n"
                . "VALUES (?, ?, ?, ?, 'fin', 'ap_bill', ?, 'ap_bill', ?, ?, NOW())"
            );
            $reference = $bill['vendor_invoice_number'] ?: ('BILL' . $billId);
            $desc      = 'AP Bill ' . $reference;
            $stmt->execute([
                $this->companyId,
                $entryDate,
                $reference,
                $desc,
                $billId,
                $billId,
                $this->userId
            ]);
            $journalId = (int)$this->db->lastInsertId();
            // Insert journal lines
            $stmtLine = $this->db->prepare(
                "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, project_id, board_id, item_id, tax_code_id, customer_id, supplier_id, reference)\n"
                . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)"
            );
            foreach ($journalLines as $jl) {
                $stmtLine->execute([
                    $journalId,
                    $jl['account_code'],
                    $jl['description'],
                    number_format($jl['debit'], 2, '.', ''),
                    number_format($jl['credit'], 2, '.', ''),
                    $jl['project_id'],
                    $jl['board_id'],
                    $jl['item_id'],
                    $jl['supplier_id'],
                    $reference
                ]);
            }
            // Update bill journal and status
            $stmt = $this->db->prepare(
                "UPDATE ap_bills SET journal_id = ?, status = 'posted' WHERE id = ? AND company_id = ?"
            );
            $stmt->execute([$journalId, $billId, $this->companyId]);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Post a supplier payment to the general ledger. A supplier payment
     * reduces accounts payable and credits the selected bank account.
     * Allocations indicate which bills are being paid and by how much.
     * If a journal already exists it is removed first. Payments in
     * locked periods cannot be posted.
     *
     * @param int $paymentId The ID of the AP payment
     * @throws Exception on locked period or missing payment
     */
    public function postSupplierPayment(int $paymentId): void
    {
        // Fetch payment
        $stmt = $this->db->prepare(
            "SELECT * FROM ap_payments WHERE id = ? AND company_id = ? LIMIT 1"
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
        // Remove existing journal if present
        if (!empty($payment['journal_id'])) {
            $this->deleteJournal((int)$payment['journal_id']);
        }
        // Fetch allocations with related bill info (supplier) and amounts
        $stmt = $this->db->prepare(
            "SELECT apa.amount, b.supplier_id, b.vendor_invoice_number, b.total\n"
            . "FROM ap_payment_allocations apa\n"
            . "JOIN ap_bills b ON b.id = apa.bill_id\n"
            . "WHERE apa.ap_payment_id = ?"
        );
        $stmt->execute([$paymentId]);
        $allocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$allocs) {
            throw new Exception('Supplier payment has no allocations');
        }
        // Determine account codes
        // Bank code: from selected bank_account_id if provided
        $bankCode = null;
        if (!empty($payment['bank_account_id'])) {
            $stmt = $this->db->prepare(
                "SELECT ga.account_code FROM gl_bank_accounts ba\n"
                . "JOIN gl_accounts ga ON ga.account_id = ba.gl_account_id\n"
                . "WHERE ba.id = ? AND ba.company_id = ? LIMIT 1"
            );
            $stmt->execute([$payment['bank_account_id'], $this->companyId]);
            $bankCode = $stmt->fetchColumn();
        }
        if (!$bankCode) {
            // Fallback to finance_bank_account_id or 1110
            $bankCode = $this->accounts->get('finance_bank_account_id', '1110');
        }
        $apCode = $this->accounts->get('finance_ap_account_id', '2110');
        // Compute total amount
        $totalAmt = 0.0;
        foreach ($allocs as $al) {
            $totalAmt += floatval($al['amount']);
        }
        // Post journal
        $this->db->beginTransaction();
        try {
            $reference = $payment['reference'] ?: ('APPAY' . $paymentId);
            $desc      = 'Supplier Payment';
            // Insert journal entry
            $stmt = $this->db->prepare(
                "INSERT INTO journal_entries (\n"
                . "company_id, entry_date, reference, description, module, ref_type, ref_id,\n"
                . "source_type, source_id, created_by, created_at\n"
                . ") VALUES (?, ?, ?, ?, 'fin', 'ap_payment', ?, 'ap_payment', ?, ?, NOW())"
            );
            $stmt->execute([
                $this->companyId,
                $entryDate,
                $reference,
                $desc,
                $paymentId,
                $paymentId,
                $this->userId
            ]);
            $journalId = (int)$this->db->lastInsertId();
            // Credit bank (money out)
            $stmtLine = $this->db->prepare(
                "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, customer_id, supplier_id, reference)\n"
                . "VALUES (?, ?, ?, 0, ?, NULL, ?, ?)"
            );
            $stmtLine->execute([
                $journalId,
                $bankCode,
                'Bank',
                number_format($totalAmt, 2, '.', ''),
                $payment['supplier_id'] ?: null,
                $reference
            ]);
            // Debit AP lines per allocation
            $stmtLine = $this->db->prepare(
                "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, customer_id, supplier_id, reference)\n"
                . "VALUES (?, ?, ?, ?, 0, NULL, ?, ?)"
            );
            foreach ($allocs as $al) {
                $amt  = floatval($al['amount']);
                $ref  = $al['vendor_invoice_number'] ?: $reference;
                $stmtLine->execute([
                    $journalId,
                    $apCode,
                    'Accounts Payable',
                    number_format($amt, 2, '.', ''),
                    $al['supplier_id'],
                    $ref
                ]);
            }
            // Update payment with journal id
            $stmt = $this->db->prepare(
                "UPDATE ap_payments SET journal_id = ? WHERE id = ? AND company_id = ?"
            );
            $stmt->execute([$journalId, $paymentId, $this->companyId]);
            // Mark bills as paid if fully settled
            foreach ($allocs as $al) {
                // Sum total allocations for each bill
                $billId = null;
                // We'll compute total allocations and update status in a single query later
            }
            // For each bill allocated, check if remaining balance is <= 0 and update status
            $stmtBillIds = $this->db->prepare(
                "SELECT DISTINCT bill_id FROM ap_payment_allocations WHERE ap_payment_id = ?"
            );
            $stmtBillIds->execute([$paymentId]);
            $billIds = $stmtBillIds->fetchAll(PDO::FETCH_COLUMN, 0);
            foreach ($billIds as $bId) {
                // Compute total paid and credited
                $stmtBal = $this->db->prepare(
                    "SELECT total,\n"
                    . "COALESCE((SELECT SUM(amount) FROM ap_payment_allocations WHERE bill_id = ?),0) AS paid,\n"
                    . "COALESCE((SELECT SUM(amount) FROM vendor_credit_allocations WHERE bill_id = ?),0) AS credited\n"
                    . "FROM ap_bills WHERE id = ? AND company_id = ?"
                );
                $stmtBal->execute([$bId, $bId, $bId, $this->companyId]);
                $row = $stmtBal->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $due = floatval($row['total']) - (floatval($row['paid']) + floatval($row['credited']));
                    if ($due <= 0.0001) {
                        $stmtUpd = $this->db->prepare(
                            "UPDATE ap_bills SET status = 'paid' WHERE id = ? AND company_id = ?"
                        );
                        $stmtUpd->execute([$bId, $this->companyId]);
                    }
                }
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Post a vendor credit to the general ledger. A vendor credit reverses
     * previously recorded expenses: it credits expense accounts and VAT
     * Input, and debits Accounts Payable. If a journal exists for
     * this credit it will be removed. Credits dated in locked periods
     * cannot be posted.
     *
     * @param int $creditId The vendor credit ID
     * @throws Exception if invalid or locked
     */
    public function postVendorCredit(int $creditId): void
    {
        // Fetch vendor credit
        $stmt = $this->db->prepare(
            "SELECT * FROM vendor_credits WHERE id = ? AND company_id = ? LIMIT 1"
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
            $this->deleteJournal((int)$credit['journal_id']);
        }
        // Fetch credit lines
        $stmt = $this->db->prepare(
            "SELECT quantity, unit_price, discount, tax_rate, gl_account_id, item_description\n"
            . "FROM vendor_credit_lines WHERE credit_id = ? ORDER BY sort_order"
        );
        $stmt->execute([$creditId]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$lines) {
            throw new Exception('Vendor credit has no lines');
        }
        // Account codes
        $apCode   = $this->accounts->get('finance_ap_account_id', '2110');
        $vatInCode = $this->accounts->get('finance_vat_input_account_id', '2130');
        // Compute totals and build lines
        $journalLines = [];
        $netTotal = 0.0;
        $vatTotal = 0.0;
        foreach ($lines as $li) {
            $qty      = floatval($li['quantity']);
            $price    = floatval($li['unit_price']);
            $discount = isset($li['discount']) ? floatval($li['discount']) : 0.0;
            $taxRate  = isset($li['tax_rate']) ? floatval($li['tax_rate']) : 0.0;
            $net      = ($qty * $price) - $discount;
            $vat      = ($taxRate > 0) ? $net * ($taxRate / 100.0) : 0.0;
            $acctCode = null;
            if (!empty($li['gl_account_id'])) {
                $acctCode = $this->accounts->getById($li['gl_account_id']);
            }
            if (!$acctCode) {
                $acctCode = $this->accounts->get('finance_expense_account_id', '5000');
            }
            // Credit expense (reverse cost)
            $journalLines[] = [
                'account_code' => $acctCode,
                'description'  => $li['item_description'] ?: 'Vendor credit',
                'debit'        => 0.0,
                'credit'       => $net,
                'supplier_id'  => (int)$credit['supplier_id'],
                'reference'    => $credit['credit_number']
            ];
            $netTotal += $net;
            $vatTotal += $vat;
        }
        // Credit VAT Input (reverse VAT claimed)
        if ($vatTotal > 0.0001) {
            $journalLines[] = [
                'account_code' => $vatInCode,
                'description'  => 'VAT Input (Vendor Credit)',
                'debit'        => 0.0,
                'credit'       => $vatTotal,
                'supplier_id'  => (int)$credit['supplier_id'],
                'reference'    => $credit['credit_number']
            ];
        }
        // Debit AP for total credit (net + vat)
        $total = $netTotal + $vatTotal;
        $journalLines[] = [
            'account_code' => $apCode,
            'description'  => 'Accounts Payable',
            'debit'        => $total,
            'credit'       => 0.0,
            'supplier_id'  => (int)$credit['supplier_id'],
            'reference'    => $credit['credit_number']
        ];
        // Post to GL
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO journal_entries (company_id, entry_date, reference, description, module, ref_type, ref_id,\n"
                . "source_type, source_id, created_by, created_at)\n"
                . "VALUES (?, ?, ?, ?, 'fin', 'vendor_credit', ?, 'vendor_credit', ?, ?, NOW())"
            );
            $reference = $credit['credit_number'];
            $desc      = 'Vendor Credit ' . $reference;
            $stmt->execute([
                $this->companyId,
                $entryDate,
                $reference,
                $desc,
                $creditId,
                $creditId,
                $this->userId
            ]);
            $journalId = (int)$this->db->lastInsertId();
            // Insert lines
            $stmtLine = $this->db->prepare(
                "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, customer_id, supplier_id, reference)\n"
                . "VALUES (?, ?, ?, ?, ?, NULL, ?, ?)"
            );
            foreach ($journalLines as $jl) {
                $stmtLine->execute([
                    $journalId,
                    $jl['account_code'],
                    $jl['description'],
                    number_format($jl['debit'], 2, '.', ''),
                    number_format($jl['credit'], 2, '.', ''),
                    $jl['supplier_id'],
                    $jl['reference']
                ]);
            }
            // Update credit with journal id and status
            $stmt = $this->db->prepare(
                "UPDATE vendor_credits SET journal_id = ?, status = 'applied' WHERE id = ? AND company_id = ?"
            );
            $stmt->execute([$journalId, $creditId, $this->companyId]);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
