<?php
// /qi/lib/QuoteConverter.php
// Converts an accepted quote into a draft invoice (line items, headings,
// payment schedule, status -> 'converted'), then runs the calendar hook.
// Caller must NOT already be inside a transaction.
//
// - Idempotent: a quote is turned into at most one invoice. Converting again
//   (double click, the customer accepting twice, a retry) returns the invoice
//   that already exists instead of creating another.
// - Amounts are recomputed from the lines in integer cents (QiMoney), so a
//   quote saved by older code (net line totals, float rounding) still becomes
//   an invoice that matches its ledger posting to the cent.
// - A foreign-currency invoice takes the exchange rate of its own date (the
//   quote's rate may be weeks old); the quote's rate is the fallback.

require_once __DIR__ . '/SequenceAllocator.php';
require_once __DIR__ . '/LineHeadings.php';
require_once __DIR__ . '/QiMoney.php';
require_once __DIR__ . '/QiActivity.php';
require_once __DIR__ . '/Currencies.php';

class QuoteConverter {
    private PDO $DB;

    public function __construct(PDO $DB) {
        $this->DB = $DB;
    }

    /**
     * Returns ['invoice_id' => int, 'invoice_number' => string, 'created' => bool].
     * Throws Exception on failure.
     */
    public function convert(int $quoteId, int $companyId, int $userId): array {
        $DB = $this->DB;
        try { LineHeadings::ensureTable($DB); } catch (Throwable $e) { /* headings are optional */ }

        $DB->beginTransaction();
        try {
            $stmt = $DB->prepare("SELECT * FROM quotes WHERE id = ? AND company_id = ? FOR UPDATE");
            $stmt->execute([$quoteId, $companyId]);
            $quote = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$quote) throw new Exception('Quote not found');

            // One quote, one invoice
            $stmt = $DB->prepare("SELECT id, invoice_number FROM invoices WHERE quote_id = ? AND company_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1");
            $stmt->execute([$quoteId, $companyId]);
            if ($existing = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($quote['status'] !== 'converted') {
                    $DB->prepare("UPDATE quotes SET status = 'converted', updated_at = NOW() WHERE id = ? AND company_id = ?")->execute([$quoteId, $companyId]);
                }
                $DB->commit();
                return ['invoice_id' => (int)$existing['id'], 'invoice_number' => (string)$existing['invoice_number'], 'created' => false];
            }
            if ($quote['status'] !== 'accepted') throw new Exception('Only an accepted quote can be turned into an invoice');

            $issueDate = date('Y-m-d');
            $stmtTerms = $DB->prepare("SELECT default_payment_terms FROM qi_settings WHERE company_id = ?");
            $stmtTerms->execute([$companyId]);
            $termsRow = $stmtTerms->fetch(PDO::FETCH_ASSOC);
            $paymentDays = ($termsRow && $termsRow['default_payment_terms'] !== null) ? max(0, (int)$termsRow['default_payment_terms']) : 30;
            $dueDate = date('Y-m-d', strtotime("+{$paymentDays} days"));

            $currency = Currencies::isValid($quote['currency'] ?? null) ? strtoupper($quote['currency']) : Currencies::BASE;
            $rate = 1.0;
            if ($currency !== Currencies::BASE) {
                require_once __DIR__ . '/../../finances/lib/CurrencyService.php';
                $rate = (float)((new CurrencyService($DB, $companyId))->getRate($currency, $issueDate) ?? 0);
                if ($rate <= 0) $rate = (float)($quote['exchange_rate'] ?? 0);
                if ($rate <= 0) throw new Exception("No {$currency} exchange rate for {$issueDate}. Add one under Finances → Exchange rates, then try again.");
            }

            $stmt = $DB->prepare("SELECT * FROM quote_lines WHERE quote_id = ? ORDER BY sort_order, id");
            $stmt->execute([$quoteId]);
            $quoteLines = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$quoteLines) throw new Exception('This quote has no lines to invoice');
            $t = QiMoney::totals($quoteLines);

            [$invoiceNumber] = (new SequenceAllocator($DB))->allocate($companyId, 'invoice', $issueDate, true);

            $stmt = $DB->prepare("INSERT INTO invoices (
                    company_id, invoice_number, quote_id, customer_id, contact_id, project_id,
                    issue_date, due_date, status, subtotal, discount, tax, total, balance_due,
                    currency, exchange_rate, terms, notes, reference, customer_notes, public_token, created_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            $stmt->execute([
                $companyId, $invoiceNumber, $quoteId, $quote['customer_id'], $quote['contact_id'], $quote['project_id'],
                $issueDate, $dueDate,
                $t['net'] / 100, $t['discount'] / 100, $t['vat'] / 100, $t['total'] / 100, $t['total'] / 100,
                $currency, $rate, $quote['terms'], $quote['notes'],
                $quote['reference'] ?? null, $quote['customer_notes'] ?? null,
                bin2hex(random_bytes(16)), $userId,
            ]);
            $invoiceId = (int)$DB->lastInsertId();

            // Carry the full tax profile onto the invoice lines — the GL
            // journal and VAT201 are derived from these columns. line_total is
            // the VAT-inclusive amount, recomputed with the ledger's rounding.
            $insertLine = $DB->prepare("INSERT INTO invoice_lines (
                    invoice_id, item_description, quantity, unit, unit_price,
                    discount, tax_rate, tax_code_id, gl_account_id, project_item_id, line_total, sort_order
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($quoteLines as $line) {
                $l = QiMoney::line($line['quantity'], $line['unit_price'], $line['discount'] ?? 0, $line['tax_rate'] ?? 0);
                $insertLine->execute([
                    $invoiceId,
                    $line['item_description'],
                    $line['quantity'],
                    $line['unit'] ?? '',
                    $line['unit_price'],
                    $line['discount'] ?? 0,
                    $line['tax_rate'] ?? 0,
                    $line['tax_code_id'] ?? null,
                    $line['gl_account_id'] ?? null,
                    $line['project_item_id'] ?? null,
                    $l['gross'] / 100,
                    $line['sort_order'],
                ]);
            }

            // Section headings ride along so the invoice keeps the quote's
            // structure (sort_order values carry over unchanged).
            LineHeadings::replace($DB, $companyId, LineHeadings::TYPE_INVOICE, $invoiceId, LineHeadings::fetch($DB, LineHeadings::TYPE_QUOTE, $quoteId));

            if (!empty($quote['has_milestones'])) {
                $stmt = $DB->prepare("SELECT * FROM payment_milestones WHERE entity_type = 'quote' AND entity_id = ? AND company_id = ? ORDER BY sort_order, id");
                $stmt->execute([$quoteId, $companyId]);
                $quoteMilestones = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($quoteMilestones)) {
                    // the schedule's cents must add up to the invoice total exactly
                    $totalC = $t['total'];
                    $amounts = array_map(fn($ms) => (int)round($totalC * (float)$ms['percentage'] / 100), $quoteMilestones);
                    $amounts[count($amounts) - 1] += $totalC - array_sum($amounts);
                    $msInsert = $DB->prepare("INSERT INTO payment_milestones (entity_type, entity_id, company_id, label, percentage, amount, due_date, status, sort_order) VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, ?)");
                    foreach ($quoteMilestones as $idx => $ms) {
                        $msInsert->execute([
                            $invoiceId, $companyId, $ms['label'], $ms['percentage'], $amounts[$idx] / 100,
                            $ms['due_date'], $idx === 0 ? 'due' : 'pending', $ms['sort_order'],
                        ]);
                    }
                    $DB->prepare("UPDATE invoices SET has_milestones = 1 WHERE id = ?")->execute([$invoiceId]);
                }
            }

            $DB->prepare("UPDATE quotes SET status = 'converted', updated_at = NOW() WHERE id = ? AND company_id = ?")->execute([$quoteId, $companyId]);
            QiActivity::log($DB, $companyId, 'quote', $quoteId, 'converted', $invoiceNumber, $userId ?: null);
            QiActivity::log($DB, $companyId, 'invoice', $invoiceId, 'created', 'From quote ' . $quote['quote_number'], $userId ?: null);

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

        // The converted invoice is created as a DRAFT: it posts to the GL
        // when it is issued (send / issue), not here — a draft is not a tax
        // invoice and must not appear in the ledger.

        return [
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoiceNumber,
            'created' => true,
        ];
    }
}
