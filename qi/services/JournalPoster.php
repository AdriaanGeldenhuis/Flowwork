<?php
// qi/services/JournalPoster.php
// Service class responsible for posting accounting journal entries for invoices, payments and credit notes.

/**
 * JournalPoster
 *
 * This class encapsulates logic for creating journal entries in the general ledger.
 * It reads company settings to determine which GL accounts to use for Accounts Receivable,
 * Sales income, VAT output and Bank. If settings are missing it falls back to sensible
 * default account codes.
 */
class JournalPoster
{
    private $db;
    private $companyId;
    private $userId;

    /**
     * Constructor
     *
     * @param PDO    $db        The PDO instance
     * @param int    $companyId The company id
     * @param int    $userId    The current user id (for audit)
     */
    public function __construct(PDO $db, int $companyId, int $userId)
    {
        $this->db        = $db;
        $this->companyId = $companyId;
        $this->userId    = $userId;
    }

    /**
     * Fetch a GL account code based on a company setting key. If the setting is
     * blank or references a non-existent account, falls back to the provided
     * default code.
     *
     * @param string $settingKey  The key in company_settings (e.g. finance_ar_account_id)
     * @param string $defaultCode The fallback account code (e.g. '1200')
     * @return string The account code to use
     */
    private function getAccountCodeBySetting(string $settingKey, string $defaultCode): string
    {
        // Look up setting value
        $stmt = $this->db->prepare(
            "SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = ? LIMIT 1"
        );
        $stmt->execute([$this->companyId, $settingKey]);
        $value = $stmt->fetchColumn();
        if (!$value) {
            return $defaultCode;
        }
        // If numeric, treat as account_id and fetch account_code
        if (is_numeric($value)) {
            $stmt = $this->db->prepare(
                "SELECT account_code FROM gl_accounts WHERE account_id = ? AND company_id = ? LIMIT 1"
            );
            $stmt->execute([(int)$value, $this->companyId]);
            $code = $stmt->fetchColumn();
            return $code ?: $defaultCode;
        }
        // Otherwise assume the setting contains the account code directly
        return trim($value);
    }

    /**
     * Resolve an account code from a gl_account_id. If not found returns null.
     *
     * @param int $accountId
     * @return string|null
     */
    private function getAccountCodeById(?int $accountId): ?string
    {
        if (!$accountId) {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT account_code FROM gl_accounts WHERE account_id = ? AND company_id = ? LIMIT 1"
        );
        $stmt->execute([$accountId, $this->companyId]);
        $code = $stmt->fetchColumn();
        return $code ?: null;
    }

    /**
     * Post a journal entry for a newly created or updated invoice. This method
     * calculates the net sales and VAT amounts per line, groups them by GL
     * account and creates a balanced journal: Debit AR, Credit Sales and VAT.
     *
     * @param int $invoiceId
     * @return void
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
            return;
        }
        // Remove existing journal entry if present (re-post on update)
        if (!empty($invoice['journal_id'])) {
            $this->deleteJournal((int)$invoice['journal_id']);
        }
        // Fetch invoice lines with GL account ids and tax rates
        $stmt = $this->db->prepare(
            "SELECT quantity, unit_price, discount, tax_rate, gl_account_id FROM invoice_lines WHERE invoice_id = ?"
        );
        $stmt->execute([$invoiceId]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$lines) {
            return;
        }
        // Determine default accounts
        $arCode   = $this->getAccountCodeBySetting('finance_ar_account_id', '1200');
        $salesDef = $this->getAccountCodeBySetting('finance_sales_account_id', '4100');
        $vatCode  = $this->getAccountCodeBySetting('finance_vat_output_account_id', '2120');

        // Aggregate net sales per account and total VAT
        $salesTotals = [];
        $totalVat    = 0.0;
        foreach ($lines as $li) {
            $qty      = floatval($li['quantity']);
            $price    = floatval($li['unit_price']);
            $discount = floatval($li['discount']);
            $taxRate  = floatval($li['tax_rate']);
            $net      = ($qty * $price) - $discount;
            $vat      = $net * ($taxRate / 100);
            // Determine which sales account to use
            $lineCode = $this->getAccountCodeById($li['gl_account_id']) ?: $salesDef;
            if (!isset($salesTotals[$lineCode])) {
                $salesTotals[$lineCode] = 0.0;
            }
            $salesTotals[$lineCode] += $net;
            $totalVat += $vat;
        }
        // Total invoice amount equals sum of net sales and VAT
        $total = array_sum($salesTotals) + $totalVat;
        // Begin new transaction for journal posting
        $this->db->beginTransaction();
        try {
            // Insert journal entry
            $stmt = $this->db->prepare(
                "INSERT INTO journal_entries (
                    company_id, entry_date, reference, description, module, ref_type, ref_id,
                    source_type, source_id, created_by, created_at
                ) VALUES (?, ?, ?, ?, 'qi', 'invoice', ?, 'invoice', ?, ?, NOW())"
            );
            $entryDate  = $invoice['issue_date'] ?? date('Y-m-d');
            $reference  = $invoice['invoice_number'];
            $desc       = 'Invoice ' . $invoice['invoice_number'];
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
                "INSERT INTO journal_lines (
                    journal_id, account_code, description, debit, credit, customer_id, reference
                ) VALUES (?, ?, ?, ?, 0, ?, ?)"
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
                "INSERT INTO journal_lines (
                    journal_id, account_code, description, debit, credit, customer_id, reference
                ) VALUES (?, ?, ?, 0, ?, ?, ?)"
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
            // Update invoice with journal id
            $stmt = $this->db->prepare("UPDATE invoices SET journal_id = ? WHERE id = ? AND company_id = ?");
            $stmt->execute([$journalId, $invoiceId, $this->companyId]);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log('Journal posting for invoice failed: ' . $e->getMessage());
            // Do not throw further
        }
    }

    /**
     * Post a journal entry for a payment. This records a debit to Bank and
     * credits Accounts Receivable for each allocation. If multiple invoices
     * are allocated, multiple credit lines are created.
     *
     * @param int $paymentId
     * @return void
     */
    public function postPayment(int $paymentId): void
    {
        // Fetch payment
        $stmt = $this->db->prepare(
            "SELECT * FROM payments WHERE id = ? AND company_id = ? LIMIT 1"
        );
        $stmt->execute([$paymentId, $this->companyId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            return;
        }
        // Remove existing journal entry if present
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
            return;
        }
        // Determine accounts
        $bankCode = $this->getAccountCodeBySetting('finance_bank_account_id', '1110');
        $arCode   = $this->getAccountCodeBySetting('finance_ar_account_id', '1200');
        // Compute total amount
        $totalAmt = 0.0;
        foreach ($allocs as $al) {
            $totalAmt += floatval($al['amount']);
        }
        // Begin journal transaction
        $this->db->beginTransaction();
        try {
            $entryDate = $payment['payment_date'];
            $reference = $payment['reference'] ?: ('PAY' . $paymentId);
            $desc      = 'Payment';
            // Insert journal entry
            $stmt = $this->db->prepare(
                "INSERT INTO journal_entries (
                    company_id, entry_date, reference, description, module, ref_type, ref_id,
                    source_type, source_id, created_by, created_at
                ) VALUES (?, ?, ?, ?, 'qi', 'payment', ?, 'payment', ?, ?, NOW())"
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
            // For payments we don't link to a specific customer on the bank line (customer_id null)
            $stmtLine->execute([
                $journalId,
                $bankCode,
                'Bank',
                number_format($totalAmt, 2, '.', ''),
                null,
                $reference
            ]);
            // Credit AR lines for each allocation
            $stmtLine = $this->db->prepare(
                "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, customer_id, reference)
                 VALUES (?, ?, ?, 0, ?, ?, ?)"
            );
            foreach ($allocs as $al) {
                $amt = floatval($al['amount']);
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
            $stmt = $this->db->prepare("UPDATE payments SET journal_id = ? WHERE id = ? AND company_id = ?");
            $stmt->execute([$journalId, $paymentId, $this->companyId]);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log('Journal posting for payment failed: ' . $e->getMessage());
        }
    }

    /**
     * Post a journal entry for a credit note. This reverses sales and VAT and
     * adjusts accounts receivable. It should be called when a credit note is
     * approved or applied.
     *
     * @param int $creditNoteId
     * @return void
     */
    public function postCreditNote(int $creditNoteId): void
    {
        // Fetch credit note
        $stmt = $this->db->prepare(
            "SELECT * FROM credit_notes WHERE id = ? AND company_id = ? LIMIT 1"
        );
        $stmt->execute([$creditNoteId, $this->companyId]);
        $credit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$credit) {
            return;
        }
        if (!empty($credit['journal_id'])) {
            $this->deleteJournal((int)$credit['journal_id']);
        }
        // Fetch lines
        $stmt = $this->db->prepare(
            "SELECT quantity, unit_price, discount, tax_rate, gl_account_id FROM credit_note_lines WHERE credit_note_id = ?"
        );
        $stmt->execute([$creditNoteId]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$lines) {
            return;
        }
        // Determine accounts
        $arCode   = $this->getAccountCodeBySetting('finance_ar_account_id', '1200');
        $salesDef = $this->getAccountCodeBySetting('finance_sales_account_id', '4100');
        $vatCode  = $this->getAccountCodeBySetting('finance_vat_output_account_id', '2120');
        // Aggregate sales per account and VAT
        $salesTotals = [];
        $totalVat    = 0.0;
        foreach ($lines as $li) {
            $qty      = floatval($li['quantity']);
            $price    = floatval($li['unit_price']);
            $discount = floatval($li['discount']);
            $taxRate  = floatval($li['tax_rate']);
            $net      = ($qty * $price) - $discount;
            $vat      = $net * ($taxRate / 100);
            $lineCode = $this->getAccountCodeById($li['gl_account_id']) ?: $salesDef;
            if (!isset($salesTotals[$lineCode])) {
                $salesTotals[$lineCode] = 0.0;
            }
            $salesTotals[$lineCode] += $net;
            $totalVat += $vat;
        }
        $totalCredit = array_sum($salesTotals) + $totalVat;
        // Create journal entry
        $this->db->beginTransaction();
        try {
            $entryDate = $credit['issue_date'] ?? date('Y-m-d');
            $reference = $credit['credit_note_number'];
            $desc      = 'Credit Note ' . $reference;
            $stmt = $this->db->prepare(
                "INSERT INTO journal_entries (
                    company_id, entry_date, reference, description, module, ref_type, ref_id,
                    source_type, source_id, created_by, created_at
                ) VALUES (?, ?, ?, ?, 'qi', 'credit_note', ?, 'credit_note', ?, ?, NOW())"
            );
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
            // Debit Sales accounts (reverse revenue)
            $stmtLine = $this->db->prepare(
                "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, customer_id, reference)
                 VALUES (?, ?, ?, ?, 0, ?, ?)"
            );
            foreach ($salesTotals as $code => $amt) {
                $stmtLine->execute([
                    $journalId,
                    $code,
                    'Sales Income',
                    number_format($amt, 2, '.', ''),
                    $credit['customer_id'],
                    $reference
                ]);
            }
            // Debit VAT Output
            if ($totalVat > 0.0001) {
                $stmtLine->execute([
                    $journalId,
                    $vatCode,
                    'VAT Output',
                    number_format($totalVat, 2, '.', ''),
                    $credit['customer_id'],
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
                number_format($totalCredit, 2, '.', ''),
                $credit['customer_id'],
                $reference
            ]);
            // Update credit note with journal id
            $stmt = $this->db->prepare("UPDATE credit_notes SET journal_id = ? WHERE id = ? AND company_id = ?");
            $stmt->execute([$journalId, $creditNoteId, $this->companyId]);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log('Journal posting for credit note failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete an existing journal entry and its lines. Used to re-post when a
     * document is updated. If the entry does not exist this is a no-op.
     *
     * @param int $journalId
     * @return void
     */
    private function deleteJournal(int $journalId): void
    {
        if ($journalId <= 0) {
            return;
        }
        $this->db->beginTransaction();
        try {
            // Delete lines first due to FK constraints
            $stmt = $this->db->prepare("DELETE FROM journal_lines WHERE journal_id = ?");
            $stmt->execute([$journalId]);
            $stmt = $this->db->prepare("DELETE FROM journal_entries WHERE id = ?");
            $stmt->execute([$journalId]);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log('Failed to delete journal entry ' . $journalId . ': ' . $e->getMessage());
        }
    }
}
