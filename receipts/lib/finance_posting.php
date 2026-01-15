<?php
/**
 * Finance Posting Engine
 * Creates double-entry journal entries for AP invoices
 */

class FinancePosting {
    private $DB;
    private $companyId;
    private $userId;

    public function __construct($DB, $companyId, $userId) {
        $this->DB = $DB;
        $this->companyId = $companyId;
        $this->userId = $userId;
    }

    public function postAPInvoice($invoiceId) {
        // Fetch invoice
        $stmt = $this->DB->prepare("
            SELECT i.*, ca.name as supplier_name
            FROM invoices i
            JOIN crm_accounts ca ON ca.id = i.customer_id
            WHERE i.id = ? AND i.company_id = ?
        ");
        $stmt->execute([$invoiceId, $this->companyId]);
        $invoice = $stmt->fetch();

        if (!$invoice) {
            throw new Exception('Invoice not found');
        }

        // Fetch lines
        $stmt = $this->DB->prepare("
            SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order
        ");
        $stmt->execute([$invoiceId]);
        $lines = $stmt->fetchAll();

        // Create journal entry
        $journalId = $this->createJournalHeader($invoice);

        // Post lines: Dr Expense / Cr AP
        foreach ($lines as $line) {
            // Debit: Expense account
            $this->createJournalLine($journalId, [
                'account' => '5000', // Default expense (from settings or line)
                'description' => $line['item_description'],
                'debit' => $line['line_total'],
                'credit' => 0,
                'reference' => $invoice['invoice_number']
            ]);
        }

        // Debit: VAT Input
        if ($invoice['tax'] > 0) {
            $this->createJournalLine($journalId, [
                'account' => '1510', // VAT Input
                'description' => 'VAT on ' . $invoice['invoice_number'],
                'debit' => $invoice['tax'],
                'credit' => 0,
                'reference' => $invoice['invoice_number']
            ]);
        }

        // Credit: Accounts Payable
        $this->createJournalLine($journalId, [
            'account' => '2100', // AP Control
            'description' => 'AP: ' . $invoice['supplier_name'],
            'debit' => 0,
            'credit' => $invoice['total'],
            'reference' => $invoice['invoice_number']
        ]);

        // Link journal to invoice
        $stmt = $this->DB->prepare("UPDATE invoices SET journal_id = ? WHERE id = ?");
        $stmt->execute([$journalId, $invoiceId]);

        return $journalId;
    }

    private function createJournalHeader($invoice) {
        $stmt = $this->DB->prepare("
            INSERT INTO journal_entries (
                company_id, entry_date, reference, description, source_type, source_id, created_by
            ) VALUES (?, ?, ?, ?, 'invoice', ?, ?)
        ");
        $stmt->execute([
            $this->companyId,
            $invoice['issue_date'],
            $invoice['invoice_number'],
            'AP Invoice: ' . $invoice['invoice_number'],
            $invoice['id'],
            $this->userId
        ]);

        return $this->DB->lastInsertId();
    }

    private function createJournalLine($journalId, $data) {
        $stmt = $this->DB->prepare("
            INSERT INTO journal_lines (
                journal_id, account_code, description, debit, credit, reference
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $journalId,
            $data['account'],
            $data['description'],
            $data['debit'],
            $data['credit'],
            $data['reference']
        ]);
    }
}