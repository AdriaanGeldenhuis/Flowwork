<?php
// /qi/lib/QuoteConverter.php
// Converts an accepted quote into a draft invoice (line items, milestones,
// status -> 'converted'), then runs calendar + journal post-commit hooks.
// Caller must NOT already be inside a transaction.

require_once __DIR__ . '/SequenceAllocator.php';

class QuoteConverter {
    private PDO $DB;

    public function __construct(PDO $DB) {
        $this->DB = $DB;
    }

    /**
     * Returns ['invoice_id' => int, 'invoice_number' => string].
     * Throws Exception on failure.
     */
    public function convert(int $quoteId, int $companyId, int $userId): array {
        $DB = $this->DB;

        $DB->beginTransaction();
        try {
            $stmt = $DB->prepare("SELECT * FROM quotes WHERE id = ? AND company_id = ? AND status = 'accepted'");
            $stmt->execute([$quoteId, $companyId]);
            $quote = $stmt->fetch();
            if (!$quote) throw new Exception('Quote not found or not accepted');

            $alloc = new SequenceAllocator($DB);
            [$invoiceNumber, $seqNum] = $alloc->allocate($companyId, 'invoice', null, true);

            $stmtTerms = $DB->prepare("SELECT default_payment_terms FROM qi_settings WHERE company_id = ?");
            $stmtTerms->execute([$companyId]);
            $termsRow = $stmtTerms->fetch();
            $paymentDays = ($termsRow && $termsRow['default_payment_terms']) ? (int)$termsRow['default_payment_terms'] : 30;
            $dueDate = date('Y-m-d', strtotime("+{$paymentDays} days"));

            $stmt = $DB->prepare("INSERT INTO invoices (
                    company_id, invoice_number, quote_id, customer_id, contact_id, project_id,
                    issue_date, due_date, status, subtotal, discount, tax, total, balance_due,
                    currency, exchange_rate, terms, notes, created_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            $stmt->execute([
                $companyId,
                $invoiceNumber,
                $quoteId,
                $quote['customer_id'],
                $quote['contact_id'],
                $quote['project_id'],
                date('Y-m-d'),
                $dueDate,
                $quote['subtotal'],
                $quote['discount'],
                $quote['tax'],
                $quote['total'],
                $quote['total'],
                $quote['currency'],
                $quote['exchange_rate'] ?? 1,
                $quote['terms'],
                $quote['notes'],
                $userId,
            ]);
            $invoiceId = (int)$DB->lastInsertId();

            $stmt = $DB->prepare("SELECT * FROM quote_lines WHERE quote_id = ? ORDER BY sort_order");
            $stmt->execute([$quoteId]);
            $quoteLines = $stmt->fetchAll();

            $insertLine = $DB->prepare("INSERT INTO invoice_lines (
                    invoice_id, item_description, quantity, unit_price, line_total, sort_order
                ) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($quoteLines as $line) {
                $insertLine->execute([
                    $invoiceId,
                    $line['item_description'],
                    $line['quantity'],
                    $line['unit_price'],
                    $line['line_total'],
                    $line['sort_order'],
                ]);
            }

            if (!empty($quote['has_milestones'])) {
                $stmt = $DB->prepare("SELECT * FROM payment_milestones WHERE entity_type = 'quote' AND entity_id = ? AND company_id = ? ORDER BY sort_order");
                $stmt->execute([$quoteId, $companyId]);
                $quoteMilestones = $stmt->fetchAll();
                if (!empty($quoteMilestones)) {
                    $msInsert = $DB->prepare("INSERT INTO payment_milestones (entity_type, entity_id, company_id, label, percentage, amount, due_date, status, sort_order) VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, ?)");
                    foreach ($quoteMilestones as $idx => $ms) {
                        $msAmount = round($quote['total'] * ($ms['percentage'] / 100), 2);
                        // First phase is immediately payable; the rest wait their turn.
                        $msStatus = ($idx === 0) ? 'due' : 'pending';
                        $msInsert->execute([
                            $invoiceId,
                            $companyId,
                            $ms['label'],
                            $ms['percentage'],
                            $msAmount,
                            $ms['due_date'],
                            $msStatus,
                            $ms['sort_order'],
                        ]);
                    }
                    $stmt = $DB->prepare("UPDATE invoices SET has_milestones = 1 WHERE id = ?");
                    $stmt->execute([$invoiceId]);
                }
            }

            $stmt = $DB->prepare("UPDATE quotes SET status = 'converted', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$quoteId]);

            $DB->commit();
        } catch (Exception $e) {
            if ($DB->inTransaction()) $DB->rollBack();
            throw $e;
        }

        try {
            require_once __DIR__ . '/../services/CalendarHook.php';
            $calendarHook = new CalendarHook($DB);
            $calendarHook->handleInvoiceEvent($companyId, $invoiceId, $invoiceNumber, $dueDate, $userId);
        } catch (Exception $chEx) {
            error_log('Calendar hook for invoice failed: ' . $chEx->getMessage());
        }

        try {
            require_once __DIR__ . '/../services/JournalPoster.php';
            $poster = new JournalPoster($DB, $companyId, $userId);
            $poster->postInvoice($invoiceId);
        } catch (Exception $e) {
            error_log('Invoice journal posting failed: ' . $e->getMessage());
        }

        return [
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoiceNumber,
        ];
    }
}
