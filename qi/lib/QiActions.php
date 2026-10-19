<?php
/**
 * QiActions — everything that happens to a document after it is written:
 * quotes are sent, accepted, declined and invoiced; invoices are issued,
 * paid, refunded, voided, reopened, written off, archived, deleted and
 * restored; credit notes are approved, applied, unapplied and cancelled.
 *
 * Every action runs in one transaction with the document row locked, checks
 * the document's state first (so a double click or a stale page can never do
 * something twice or out of order), posts to the ledger through
 * PostingService inside that same transaction, and writes the document's
 * timeline (QiActivity). PDFs and the Drive copy are refreshed afterwards.
 *
 * User-facing problems throw QiInvalid (defined in QiWriter.php).
 */

require_once __DIR__ . '/QiWriter.php';
require_once __DIR__ . '/QiActivity.php';
require_once __DIR__ . '/QiMoney.php';
require_once __DIR__ . '/QuoteConverter.php';
require_once __DIR__ . '/InvoiceLifecycle.php';
require_once __DIR__ . '/InvoiceDeleteHelper.php';
require_once __DIR__ . '/LineHeadings.php';
require_once __DIR__ . '/../../finances/lib/PostingService.php';

final class QiActions
{
    public const PAYMENT_METHODS = ['eft' => 'EFT', 'card' => 'Card', 'cash' => 'Cash', 'cheque' => 'Cheque', 'yoco' => 'Yoco', 'other' => 'Other'];
    private const PAYABLE = ['draft', 'sent', 'viewed', 'overdue', 'part-paid'];
    private const OPEN = ['sent', 'viewed', 'overdue', 'part-paid'];

    // ================================================================ quotes

    /**
     * Accept a quote — by a team member, or by the customer on the public page.
     * $o: by ('user'|'customer'), name (who accepted), ip, convert (default true: make the invoice)
     */
    public static function acceptQuote(PDO $db, int $companyId, ?int $userId, int $quoteId, array $o = []): array
    {
        $byCustomer = ($o['by'] ?? 'user') === 'customer';
        $name = QiWriter::text($o['name'] ?? '', 190);
        $ip = substr((string)($o['ip'] ?? ''), 0, 45) ?: null;
        $db->beginTransaction();
        try {
            $q = self::lock($db, $companyId, 'quote', $quoteId);
            $status = (string)$q['status'];
            if (in_array($status, ['accepted', 'converted'], true)) {
                $db->commit();
                return self::quoteResult($db, $companyId, $quoteId) + ['already' => true];
            }
            $expired = !empty($q['expiry_date']) && $q['expiry_date'] < date('Y-m-d');
            if ($byCustomer) {
                if (!in_array($status, ['sent', 'viewed'], true)) {
                    throw new QiInvalid($status === 'declined' ? 'This quote was declined. Please ask for a new quote.' : 'This quote can no longer be accepted.');
                }
                if ($expired) throw new QiInvalid('This quote expired on ' . date('j F Y', strtotime((string)$q['expiry_date'])) . '. Please ask for an updated quote.');
                if (mb_strlen($name) < 2) throw new QiInvalid('Type your full name to accept the quote.', 'name');
            } elseif ($status === 'cancelled') {
                throw new QiInvalid('A cancelled quote cannot be accepted.');
            }
            $db->prepare("UPDATE quotes SET status = 'accepted', accepted_at = NOW(), accepted_by_name = ?, accepted_by_ip = ?, accepted_ip = ?,
                                            declined_at = NULL, declined_ip = NULL, updated_at = NOW()
                           WHERE id = ? AND company_id = ?")
               ->execute([$name !== '' ? $name : null, $ip, $ip, $quoteId, $companyId]);
            QiActivity::log($db, $companyId, 'quote', $quoteId, 'accepted', $name !== '' ? 'By ' . $name : null, $byCustomer ? null : $userId, $byCustomer ? ($name ?: 'Customer') : null);
            $db->commit();
        } catch (Throwable $e) {
            self::rollback($db);
            throw $e;
        }

        $out = ['status' => 'accepted', 'invoice_id' => null, 'invoice_number' => null, 'convert_error' => null];
        if (($o['convert'] ?? true) !== false) {
            try {
                $owner = $userId ?: (int)($q['created_by'] ?? 0);
                $r = (new QuoteConverter($db))->convert($quoteId, $companyId, $owner);
                $out = ['status' => 'converted', 'invoice_id' => $r['invoice_id'], 'invoice_number' => $r['invoice_number'], 'convert_error' => null];
                self::after($db, $companyId, $userId, 'invoice', (int)$r['invoice_id']);
            } catch (Throwable $e) {
                error_log('[qi] convert after accept #' . $quoteId . ': ' . $e->getMessage());
                $out['convert_error'] = $e->getMessage();
            }
        }
        self::after($db, $companyId, $userId, 'quote', $quoteId);
        self::notifyCompany($db, $companyId, $quoteId, 'accepted', $name, $byCustomer);
        return $out;
    }

    /** $o: by, name, ip, reason */
    public static function declineQuote(PDO $db, int $companyId, ?int $userId, int $quoteId, array $o = []): array
    {
        $byCustomer = ($o['by'] ?? 'user') === 'customer';
        $name = QiWriter::text($o['name'] ?? '', 190);
        $reason = QiWriter::text($o['reason'] ?? '', 400);
        $db->beginTransaction();
        try {
            $q = self::lock($db, $companyId, 'quote', $quoteId);
            $status = (string)$q['status'];
            if ($status === 'declined') { $db->commit(); return ['status' => 'declined', 'already' => true]; }
            if ($status === 'converted' || self::quoteInvoice($db, $companyId, $quoteId)) {
                throw new QiInvalid('This quote has already been turned into an invoice.');
            }
            if ($byCustomer && !in_array($status, ['sent', 'viewed'], true)) throw new QiInvalid('This quote can no longer be declined.');
            if ($status === 'cancelled') throw new QiInvalid('This quote was cancelled.');
            $ip = substr((string)($o['ip'] ?? ''), 0, 45) ?: null;
            $db->prepare("UPDATE quotes SET status = 'declined', declined_at = NOW(), declined_ip = ?, accepted_at = NULL, accepted_ip = NULL,
                                            accepted_by_ip = NULL, accepted_by_name = NULL, updated_at = NOW()
                           WHERE id = ? AND company_id = ?")
               ->execute([$ip, $quoteId, $companyId]);
            QiActivity::log($db, $companyId, 'quote', $quoteId, 'declined', trim(($name !== '' ? 'By ' . $name : '') . ($reason !== '' ? ' — ' . $reason : '')) ?: null,
                            $byCustomer ? null : $userId, $byCustomer ? ($name ?: 'Customer') : null);
            $db->commit();
        } catch (Throwable $e) {
            self::rollback($db);
            throw $e;
        }
        self::after($db, $companyId, $userId, 'quote', $quoteId);
        self::notifyCompany($db, $companyId, $quoteId, 'declined', $name, $byCustomer, $reason);
        return ['status' => 'declined'];
    }

    /** Turn a quote into an invoice (accepting it on the customer's behalf if needed). */
    public static function convertQuote(PDO $db, int $companyId, int $userId, int $quoteId): array
    {
        $st = $db->prepare("SELECT status FROM quotes WHERE id = ? AND company_id = ?");
        $st->execute([$quoteId, $companyId]);
        $status = $st->fetchColumn();
        if ($status === false) throw new QiInvalid('Quote not found', '', 404);
        if (in_array($status, ['declined', 'cancelled'], true)) {
            throw new QiInvalid('A ' . $status . ' quote cannot be invoiced. Edit it to reopen it as a draft first.');
        }
        if ($status !== 'accepted' && $status !== 'converted') {
            $r = self::acceptQuote($db, $companyId, $userId, $quoteId, ['by' => 'user', 'convert' => true]);
            if (!empty($r['convert_error'])) throw new QiInvalid('The quote is accepted, but the invoice could not be made: ' . $r['convert_error']);
            return $r;
        }
        try {
            $r = (new QuoteConverter($db))->convert($quoteId, $companyId, $userId);
        } catch (Exception $e) {
            throw new QiInvalid($e->getMessage());
        }
        self::after($db, $companyId, $userId, 'invoice', (int)$r['invoice_id']);
        self::after($db, $companyId, $userId, 'quote', $quoteId);
        return ['status' => 'converted', 'invoice_id' => $r['invoice_id'], 'invoice_number' => $r['invoice_number'], 'created' => $r['created']];
    }

    /** Mark a quote as sent (it was emailed, or handed over another way). */
    public static function markQuoteSent(PDO $db, int $companyId, int $userId, int $quoteId, ?string $detail = null): array
    {
        $db->beginTransaction();
        try {
            $q = self::lock($db, $companyId, 'quote', $quoteId);
            if (!in_array($q['status'], ['draft', 'sent', 'viewed'], true)) {
                throw new QiInvalid('Only a draft or open quote can be sent.');
            }
            if (!empty($q['expiry_date']) && $q['expiry_date'] < date('Y-m-d')) {
                throw new QiInvalid('This quote expired on ' . date('j M Y', strtotime((string)$q['expiry_date'])) . '. Edit the "valid until" date first.', 'expiry_date');
            }
            $status = $q['status'] === 'draft' ? 'sent' : $q['status'];
            $token = $q['public_token'] ?: bin2hex(random_bytes(16));
            $db->prepare("UPDATE quotes SET status = ?, sent_at = NOW(), public_token = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
               ->execute([$status, $token, $quoteId, $companyId]);
            QiActivity::log($db, $companyId, 'quote', $quoteId, 'sent', $detail ?? 'Marked as sent', $userId);
            $db->commit();
        } catch (Throwable $e) {
            self::rollback($db);
            throw $e;
        }
        self::after($db, $companyId, $userId, 'quote', $quoteId);
        return ['status' => $status];
    }

    public static function deleteQuote(PDO $db, int $companyId, int $userId, int $quoteId): array
    {
        $db->beginTransaction();
        try {
            $q = self::lock($db, $companyId, 'quote', $quoteId);
            if ($q['status'] === 'converted' || ($inv = self::quoteInvoice($db, $companyId, $quoteId))) {
                throw new QiInvalid('Invoice ' . ($inv['invoice_number'] ?? '') . ' was made from this quote, so the quote is kept. Delete or void that invoice first.');
            }
            $db->prepare("DELETE FROM quote_lines WHERE quote_id = ?")->execute([$quoteId]);
            LineHeadings::delete($db, LineHeadings::TYPE_QUOTE, $quoteId);
            $db->prepare("DELETE FROM payment_milestones WHERE entity_type = 'quote' AND entity_id = ? AND company_id = ?")->execute([$quoteId, $companyId]);
            $db->prepare("DELETE FROM quotes WHERE id = ? AND company_id = ?")->execute([$quoteId, $companyId]);
            InvoiceDeleteHelper::log($db, $companyId, $userId, 'quote_deleted', ['quote_id' => $quoteId, 'number' => $q['quote_number'], 'prior_status' => $q['status']]);
            $db->commit();
        } catch (Throwable $e) {
            self::rollback($db);
            throw $e;
        }
        try {
            require_once __DIR__ . '/../services/DocumentPdfService.php';
            DocumentPdfService::removeFromDrive($db, $companyId, 'quote', (int)$q['customer_id'], (string)$q['quote_number']);
            require_once __DIR__ . '/../services/CalendarHook.php';
            (new CalendarHook($db))->deleteEvent('quote', $quoteId);
        } catch (Throwable $e) {
            error_log('[qi] delete quote cleanup: ' . $e->getMessage());
        }
        return ['deleted' => true];
    }

    // ================================================================ invoices

    /** Issue a draft: it becomes a tax invoice, gets its ledger entry and can be paid. */
    public static function issueInvoice(PDO $db, int $companyId, int $userId, int $id, ?string $detail = null): array
    {
        $db->beginTransaction();
        try {
            $inv = self::lock($db, $companyId, 'invoice', $id);
            if (!empty($inv['deleted_at'])) throw new QiInvalid('This invoice was deleted. Restore it first.');
            if ($inv['status'] === 'cancelled') throw new QiInvalid('This invoice was voided. Reopen it first.');
            $wasDraft = $inv['status'] === 'draft';
            try {
                InvoiceLifecycle::issueInvoice($db, $companyId, $userId, $id);
            } catch (Exception $e) {
                throw new QiInvalid($e->getMessage());
            }
            $db->prepare("UPDATE invoices SET sent_at = NOW(), public_token = COALESCE(NULLIF(public_token, ''), ?) WHERE id = ? AND company_id = ?")
               ->execute([bin2hex(random_bytes(16)), $id, $companyId]);
            if ($wasDraft) QiActivity::log($db, $companyId, 'invoice', $id, 'issued', null, $userId);
            if ($detail !== null) QiActivity::log($db, $companyId, 'invoice', $id, 'sent', $detail, $userId);
            $db->commit();
        } catch (Throwable $e) {
            self::rollback($db);
            throw $e;
        }
        self::after($db, $companyId, $userId, 'invoice', $id);
        return ['issued' => $wasDraft, 'status' => $wasDraft ? 'sent' : $inv['status']];
    }

    /**
     * Record money received. $p: amount, date, method, reference, notes, exchange_rate, idempotency_key.
     * $userId null = a system receipt (Yoco); $actor names it on the timeline.
     */
    public static function recordPayment(PDO $db, int $companyId, ?int $userId, int $id, array $p, ?string $actor = null): array
    {
        $amount = QiWriter::numOf($p['amount'] ?? 0, 2);
        if ($amount === null || $amount <= 0) throw new QiInvalid('Enter the amount received.', 'amount');
        $date = QiWriter::dateOf((string)($p['date'] ?? '')) ?? date('Y-m-d');
        if ($date > date('Y-m-d', strtotime('+1 day'))) throw new QiInvalid('A payment cannot be dated in the future.', 'date');
        $method = (string)($p['method'] ?? 'eft');
        if (!isset(self::PAYMENT_METHODS[$method])) $method = 'other';
        $reference = QiWriter::text($p['reference'] ?? '', 190);
        $notes = QiWriter::text($p['notes'] ?? '', 2000, true);
        $key = substr(preg_replace('/[^A-Za-z0-9:._-]/', '', (string)($p['idempotency_key'] ?? '')), 0, 64);
        $rateIn = QiWriter::numOf($p['exchange_rate'] ?? '', 6);

        $db->beginTransaction();
        try {
            if ($key !== '') {
                $st = $db->prepare("SELECT id FROM payments WHERE company_id = ? AND idempotency_key = ? LIMIT 1");
                $st->execute([$companyId, $key]);
                if ($existing = $st->fetchColumn()) {
                    $db->rollBack();
                    $st = $db->prepare("SELECT balance_due, status FROM invoices WHERE id = ? AND company_id = ?");
                    $st->execute([$id, $companyId]);
                    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                    return ['payment_id' => (int)$existing, 'balance' => round((float)($r['balance_due'] ?? 0), 2), 'status' => (string)($r['status'] ?? ''), 'duplicate' => true];
                }
            }
            $inv = self::lock($db, $companyId, 'invoice', $id);
            if (!empty($inv['deleted_at'])) throw new QiInvalid('This invoice was deleted. Restore it before recording a payment.');
            if (!empty($inv['archived_at'])) throw new QiInvalid('This invoice is archived. Restore it before recording a payment.');
            if (!in_array($inv['status'], self::PAYABLE, true)) {
                throw new QiInvalid($inv['status'] === 'paid' ? 'This invoice is already paid in full.' : 'Payments cannot be recorded against a ' . str_replace('_', ' ', $inv['status']) . ' invoice.');
            }
            $balanceC = (int)round((float)$inv['balance_due'] * 100);
            $amountC = (int)round($amount * 100);
            if ($amountC > $balanceC) {
                throw new QiInvalid('That is more than the ' . QiMoney::format($balanceC / 100, self::sym($inv)) . ' still owing.', 'amount');
            }
            if ($inv['status'] === 'draft') {
                // paying a draft issues it, so the receipt has an invoice journal to settle
                try { InvoiceLifecycle::issueInvoice($db, $companyId, (int)($userId ?: $inv['created_by']), $id); }
                catch (Exception $e) { throw new QiInvalid($e->getMessage()); }
                QiActivity::log($db, $companyId, 'invoice', $id, 'issued', 'When the first payment was recorded', $userId, $actor);
            }
            // payment-date rate for foreign invoices (realised FX); default = the invoice's own rate
            $rate = $rateIn !== null && $rateIn > 0 ? $rateIn : ((float)($inv['exchange_rate'] ?? 1) ?: 1.0);
            $receiver = (int)($userId ?: $inv['created_by']);
            $db->prepare("INSERT INTO payments (company_id, payment_date, amount, method, reference, notes, received_by, idempotency_key, exchange_rate)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$companyId, $date, $amountC / 100, $method, $reference ?: null, $notes ?: null, $receiver, $key !== '' ? $key : null, $rate]);
            $paymentId = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO payment_allocations (payment_id, invoice_id, amount) VALUES (?, ?, ?)")->execute([$paymentId, $id, $amountC / 100]);
            if (!empty($inv['has_milestones'])) {
                require_once __DIR__ . '/MilestoneAllocator.php';
                MilestoneAllocator::allocate($db, $companyId, $id, $paymentId, $amountC / 100);
            }
            $newC = $balanceC - $amountC;
            $status = $newC <= 0 ? 'paid' : 'part-paid';
            $db->prepare("UPDATE invoices SET balance_due = ?, status = ?, paid_at = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
               ->execute([max(0, $newC) / 100, $status, $status === 'paid' ? date('Y-m-d H:i:s') : $inv['paid_at'], $id, $companyId]);
            InvoiceDeleteHelper::log($db, $companyId, $receiver, 'payment_recorded', ['invoice_id' => $id, 'amount' => $amountC / 100, 'payment_id' => $paymentId]);
            // the receipt is in the ledger, or it does not exist
            (new PostingService($db, $companyId, $receiver))->postCustomerPayment($paymentId);
            QiActivity::log($db, $companyId, 'invoice', $id, 'payment',
                QiMoney::format($amountC / 100, self::sym($inv)) . ' · ' . self::PAYMENT_METHODS[$method] . ($reference !== '' ? ' · ' . $reference : ''), $userId, $actor);
            $db->commit();
        } catch (Throwable $e) {
            self::rollback($db);
            if ($e instanceof PDOException && ($e->errorInfo[1] ?? 0) == 1062 && $key !== '') {
                return ['payment_id' => 0, 'balance' => null, 'status' => '', 'duplicate' => true];
            }
            throw $e;
        }
        self::after($db, $companyId, $userId, 'invoice', $id);
        return ['payment_id' => $paymentId, 'balance' => max(0, $newC) / 100, 'status' => $status, 'duplicate' => false];
    }

    /** Money paid back to the customer. $p: amount, reference, reason. (admin / bookkeeper) */
    public static function refund(PDO $db, int $companyId, int $userId, int $id, array $p): array
    {
        $amount = QiWriter::numOf($p['amount'] ?? 0, 2);
        if ($amount === null || $amount <= 0) throw new QiInvalid('Enter the amount refunded.', 'amount');
        $reference = QiWriter::text($p['reference'] ?? '', 190);
        $reason = QiWriter::text($p['reason'] ?? '', 1000, true);
        $db->beginTransaction();
        try {
            $inv = self::lock($db, $companyId, 'invoice', $id);
            if (!in_array($inv['status'], ['paid', 'part-paid'], true)) throw new QiInvalid('Only a paid or part-paid invoice can be refunded.');
            $st = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM payment_allocations WHERE invoice_id = ?");
            $st->execute([$id]);
            $paidC = (int)round((float)$st->fetchColumn() * 100);
            $amountC = (int)round($amount * 100);
            if ($amountC > $paidC) throw new QiInvalid('That is more than the ' . QiMoney::format($paidC / 100, self::sym($inv)) . ' that was paid.', 'amount');
            $db->prepare("INSERT INTO payments (company_id, payment_date, amount, method, reference, notes, received_by) VALUES (?, ?, ?, 'refund', ?, ?, ?)")
               ->execute([$companyId, date('Y-m-d'), -$amountC / 100, $reference ?: null, $reason ?: null, $userId]);
            $refundId = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO payment_allocations (payment_id, invoice_id, amount) VALUES (?, ?, ?)")->execute([$refundId, $id, -$amountC / 100]);
            $newBalanceC = (int)round((float)$inv['balance_due'] * 100) + $amountC;
            $status = $paidC - $amountC <= 0 ? 'refunded' : ($newBalanceC > 0 ? 'part-paid' : 'paid');
            $db->prepare("UPDATE invoices SET balance_due = ?, status = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
               ->execute([$newBalanceC / 100, $status, $id, $companyId]);
            $st = $db->prepare("SELECT p.id FROM payments p JOIN payment_allocations pa ON pa.payment_id = p.id
                                 WHERE pa.invoice_id = ? AND p.company_id = ? AND p.amount > 0 AND p.refunded_at IS NULL
                                 ORDER BY p.payment_date DESC, p.id DESC LIMIT 1");
            $st->execute([$id, $companyId]);
            if ($orig = $st->fetchColumn()) {
                $db->prepare("UPDATE payments SET refunded_at = NOW(), refunded_by = ?, refund_reference = ? WHERE id = ? AND company_id = ?")
                   ->execute([$userId, $reference ?: null, (int)$orig, $companyId]);
            }
            InvoiceDeleteHelper::log($db, $companyId, $userId, 'invoice_refunded', ['invoice_id' => $id, 'amount' => $amountC / 100, 'reference' => $reference, 'reason' => $reason]);
            (new PostingService($db, $companyId, $userId))->postCustomerRefund($refundId);
            QiActivity::log($db, $companyId, 'invoice', $id, 'refund', QiMoney::format($amountC / 100, self::sym($inv)) . ($reason !== '' ? ' — ' . $reason : ''), $userId);
            $db->commit();
        } catch (Throwable $e) {
            self::rollback($db);
            throw $e;
        }
        self::after($db, $companyId, $userId, 'invoice', $id);
        return ['balance' => $newBalanceC / 100, 'status' => $status];
    }

    /** Void (cancel) an issued invoice: its ledger entry is reversed. */
    public static function voidInvoice(PDO $db, int $companyId, int $userId, int $id, string $reason): array
    {
        $reason = QiWriter::text($reason, 255);
        $db->beginTransaction();
        try {
            $inv = self::lock($db, $companyId, 'invoice', $id);
            if (in_array($inv['status'], ['cancelled', 'written_off', 'refunded'], true)) throw new QiInvalid('This invoice is already closed (' . str_replace('_', ' ', $inv['status']) . ').');
            if ($inv['status'] === 'draft') throw new QiInvalid('A draft has nothing to void — delete it instead.');
            self::assertNothingAllocated($db, $companyId, $inv, 'void');
            $db->prepare("UPDATE invoices SET status = 'cancelled', balance_due = 0, cancellation_reason = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
               ->execute([$reason ?: null, $id, $companyId]);
            (new PostingService($db, $companyId, $userId))->unpostInvoice($id, 'Invoice voided' . ($reason !== '' ? ': ' . $reason : ''));
            InvoiceDeleteHelper::log($db, $companyId, $userId, 'invoice_voided', ['invoice_id' => $id, 'prior_status' => $inv['status'], 'reason' => $reason]);
            QiActivity::log($db, $companyId, 'invoice', $id, 'voided', $reason ?: null, $userId);
            $db->commit();
        } catch (Throwable $e) {
            self::rollback($db);
            throw $e;
        }
        self::after($db, $companyId, $userId, 'invoice', $id);
        return ['status' => 'cancelled'];
    }

    /**
     * Reopen: a voided invoice becomes a draft again (owing its full total);
     * an issued one with nothing paid goes back to draft (ledger entry removed);
     * an uncollectible one is open again.
     */
    public static function reopenInvoice(PDO $db, int $companyId, int $userId, int $id): array
    {
        $db->beginTransaction();
        try {
            $inv = self::lock($db, $companyId, 'invoice', $id);
            $status = (string)$inv['status'];
            if (!empty($inv['deleted_at'])) throw new QiInvalid('Restore this invoice first.');
            if ($status === 'uncollectible') {
                $paidC = self::settledCents($db, $companyId, $id);
                $new = $paidC > 0 ? 'part-paid' : 'sent';
                $db->prepare("UPDATE invoices SET status = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")->execute([$new, $id, $companyId]);
            } elseif (in_array($status, ['sent', 'viewed', 'overdue', 'cancelled'], true)) {
                self::assertNothingAllocated($db, $companyId, $inv, 'reopen');
                // nothing is paid or credited: the whole total is owed again
                $db->prepare("UPDATE invoices SET status = 'draft', balance_due = total, cancellation_reason = NULL, paid_at = NULL, updated_at = NOW()
                               WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
                (new PostingService($db, $companyId, $userId))->unpostInvoice($id, 'Invoice reopened as a draft');
                $new = 'draft';
            } else {
                throw new QiInvalid('A ' . str_replace(['_', '-'], ' ', $status) . ' invoice cannot be reopened.');
            }
            InvoiceDeleteHelper::log($db, $companyId, $userId, 'invoice_reopened', ['invoice_id' => $id, 'prior_status' => $status, 'status' => $new]);
            QiActivity::log($db, $companyId, 'invoice', $id, 'reverted', $new === 'draft' ? 'Back to draft' : 'Open again', $userId);
            $db->commit();
        } catch (Throwable $e) {
            self::rollback($db);
            throw $e;
        }
        self::after($db, $companyId, $userId, 'invoice', $id);
        return ['status' => $new];
    }

    /** Write off part or all of the balance as a bad debt (admin / bookkeeper). */
    public static function writeOff(PDO $db, int $companyId, int $userId, int $id, $amountIn, string $reason): array
    {
        $reason = QiWriter::text($reason, 255);
        $db->beginTransaction();
        try {
            $inv = self::lock($db, $companyId, 'invoice', $id);
            if (!in_array($inv['status'], self::OPEN, true)) throw new QiInvalid('Only an open invoice can be written off.');
            $balanceC = (int)round((float)$inv['balance_due'] * 100);
            $amount = $amountIn === null || $amountIn === '' ? $balanceC / 100 : QiWriter::numOf($amountIn, 2);
            if ($amount === null || $amount <= 0) throw new QiInvalid('Enter the amount to write off.', 'amount');
            $amountC = (int)round($amount * 100);
            if ($amountC > $balanceC) throw new QiInvalid('That is more than the balance owing.', 'amount');
            $newC = $balanceC - $amountC;
            $status = $newC <= 0 ? 'written_off' : $inv['status'];
            $db->prepare("UPDATE invoices SET balance_due = ?, status = ?, write_off_amount = COALESCE(write_off_amount, 0) + ?, write_off_at = NOW(),
                                              write_off_by = ?, delete_reason = ?, updated_at = NOW()
                           WHERE id = ? AND company_id = ?")
               ->execute([$newC / 100, $status, $amountC / 100, $userId, $reason ?: null, $id, $companyId]);
            (new PostingService($db, $companyId, $userId))->postInvoiceWriteOff($id, $amountC / 100, $reason);
            InvoiceDeleteHelper::log($db, $companyId, $userId, 'invoice_written_off', ['invoice_id' => $id, 'amount' => $amountC / 100, 'reason' => $reason]);
            QiActivity::log($db, $companyId, 'invoice', $id, 'written_off', QiMoney::format($amountC / 100, self::sym($inv)) . ($reason !== '' ? ' — ' . $reason : ''), $userId);
            $db->commit();
        } catch (Throwable $e) {
            self::rollback($db);
            throw $e;
        }
        self::after($db, $companyId, $userId, 'invoice', $id);
        return ['balance' => $newC / 100, 'status' => $status];
    }

    public static function markUncollectible(PDO $db, int $companyId, int $userId, int $id, string $reason): array
    {
        $reason = QiWriter::text($reason, 255);
        $db->beginTransaction();
        try {
            $inv = self::lock($db, $companyId, 'invoice', $id);
            if (!in_array($inv['status'], self::OPEN, true)) throw new QiInvalid('Only an open invoice can be marked uncollectible.');
            $db->prepare("UPDATE invoices SET status = 'uncollectible', delete_reason = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
               ->execute([$reason ?: null, $id, $companyId]);
            InvoiceDeleteHelper::log($db, $companyId, $userId, 'invoice_marked_uncollectible', ['invoice_id' => $id, 'reason' => $reason]);
            QiActivity::log($db, $companyId, 'invoice', $id, 'uncollectible', $reason ?: null, $userId);
            $db->commit();
        } catch (Throwable $e) {
            self::rollback($db);
            throw $e;
        }
        self::after($db, $companyId, $userId, 'invoice', $id);
        return ['status' => 'uncollectible'];
    }

    public static function archiveInvoice(PDO $db, int $companyId, int $userId, int $id, bool $archive): array
    {
        $db->beginTransaction();
        try {
            $inv = self::lock($db, $companyId, 'invoice', $id);
            if ($archive && in_array($inv['status'], array_merge(['draft'], self::OPEN), true) && (float)$inv['balance_due'] > 0.004) {
                throw new QiInvalid('An invoice that is still owing cannot be archived — it would vanish from your debtors. Void it or write it off first.');
            }
            $db->prepare("UPDATE invoices SET archived_at = " . ($archive ? 'NOW()' : 'NULL') . ", archived_by = ? WHERE id = ? AND company_id = ?")
               ->execute([$archive ? $userId : null, $id, $companyId]);
            InvoiceDeleteHelper::log($db, $companyId, $userId, $archive ? 'invoice_archived' : 'invoice_unarchived', ['invoice_id' => $id]);
            QiActivity::log($db, $companyId, 'invoice', $id, $archive ? 'archived' : 'restored', $archive ? null : 'From the archive', $userId);
            $db->commit();
        } catch (Throwable $e) {
            self::rollback($db);
            throw $e;
        }
        return ['archived' => $archive];
    }

    /** Delete: a draft is removed; an issued invoice (admins) is soft-deleted with its ledger entry reversed. */
    public static function deleteInvoice(PDO $db, int $companyId, int $userId, int $id, bool $isAdmin, string $reason = ''): array
    {
        $reason = QiWriter::text($reason, 255);
        $db->beginTransaction();
        try {
            $inv = self::lock($db, $companyId, 'invoice', $id);
            if ($inv['status'] === 'draft' && empty($inv['journal_id'])) {
                InvoiceDeleteHelper::purgeChildren($db, $id, $companyId);
                $db->prepare("DELETE FROM invoices WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
                InvoiceDeleteHelper::log($db, $companyId, $userId, 'invoice_deleted', ['invoice_id' => $id, 'number' => $inv['invoice_number']]);
                $mode = 'hard';
            } else {
                if (!$isAdmin) throw new QiInvalid('Only an administrator can delete an issued invoice. Void it instead.');
                if (!empty($inv['deleted_at'])) throw new QiInvalid('This invoice is already deleted.');
                self::assertNothingAllocated($db, $companyId, $inv, 'delete');
                InvoiceDeleteHelper::reverseJournal($db, $companyId, $userId, $id);
                $db->prepare("UPDATE invoices SET deleted_at = NOW(), deleted_by = ?, delete_reason = ? WHERE id = ? AND company_id = ?")
                   ->execute([$userId, $reason ?: null, $id, $companyId]);
                InvoiceDeleteHelper::log($db, $companyId, $userId, 'invoice_soft_deleted', ['invoice_id' => $id, 'reason' => $reason]);
                QiActivity::log($db, $companyId, 'invoice', $id, 'deleted', $reason ?: null, $userId);
                $mode = 'soft';
            }
            $db->commit();
        } catch (Throwable $e) {
            self::rollback($db);
            throw $e;
        }
        try {
            require_once __DIR__ . '/../services/DocumentPdfService.php';
            DocumentPdfService::removeFromDrive($db, $companyId, 'invoice', (int)$inv['customer_id'], (string)$inv['invoice_number']);
            InvoiceDeleteHelper::removeCalendarEvent($db, $id);
        } catch (Throwable $e) {
            error_log('[qi] delete invoice cleanup: ' . $e->getMessage());
        }
        return ['mode' => $mode];
    }

    /** Undelete; an issued invoice goes back into the ledger. */
    public static function restoreInvoice(PDO $db, int $companyId, int $userId, int $id): array
    {
        $db->beginTransaction();
        try {
            $inv = self::lock($db, $companyId, 'invoice', $id);
            if (empty($inv['deleted_at'])) throw new QiInvalid('This invoice is not deleted.');
            $db->prepare("UPDATE invoices SET deleted_at = NULL, deleted_by = NULL, delete_reason = NULL, updated_at = NOW() WHERE id = ? AND company_id = ?")
               ->execute([$id, $companyId]);
            if (!in_array($inv['status'], ['draft', 'cancelled'], true) && empty($inv['journal_id'])) {
                try { InvoiceLifecycle::issueInvoice($db, $companyId, $userId, $id, false); }
                catch (Exception $e) { throw new QiInvalid('The invoice could not go back into the ledger: ' . $e->getMessage()); }
            }
            InvoiceDeleteHelper::log($db, $companyId, $userId, 'invoice_restored', ['invoice_id' => $id]);
            QiActivity::log($db, $companyId, 'invoice', $id, 'restored', null, $userId);
            $db->commit();
        } catch (Throwable $e) {
            self::rollback($db);
            throw $e;
        }
        self::after($db, $companyId, $userId, 'invoice', $id);
        return ['restored' => true];
    }

    /** Detach the payments (they stay in the ledger as money on account) so the invoice can be changed or voided. */
    public static function unapplyPayments(PDO $db, int $companyId, int $userId, int $id): array
    {
        require_once __DIR__ . '/../../finances/lib/PeriodService.php';
        $db->beginTransaction();
        try {
            $inv = self::lock($db, $companyId, 'invoice', $id);
            $st = $db->prepare("SELECT COALESCE(SUM(pa.amount), 0) FROM payment_allocations pa WHERE pa.invoice_id = ?");
            $st->execute([$id]);
            $total = (float)$st->fetchColumn();
            if ($total <= 0.004) throw new QiInvalid('There are no payments to remove.');
            $periods = new PeriodService($db, $companyId);
            $st = $db->prepare("SELECT DISTINCT p.payment_date FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id WHERE pa.invoice_id = ?");
            $st->execute([$id]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pd) {
                if ($pd && $periods->isLocked($pd)) throw new QiInvalid('A payment is dated in a filed or locked period (' . $pd . '), so it cannot be removed.');
            }
            $db->prepare("DELETE mp FROM milestone_payments mp JOIN payment_milestones pm ON pm.id = mp.milestone_id
                           WHERE pm.entity_type = 'invoice' AND pm.entity_id = ? AND pm.company_id = ?")->execute([$id, $companyId]);
            $db->prepare("DELETE FROM payment_allocations WHERE invoice_id = ?")->execute([$id]);
            $db->prepare("UPDATE payment_milestones SET amount_paid = 0, status = 'pending' WHERE entity_type = 'invoice' AND entity_id = ? AND company_id = ?")->execute([$id, $companyId]);
            $db->prepare("UPDATE payment_milestones SET status = 'due' WHERE entity_type = 'invoice' AND entity_id = ? AND company_id = ? ORDER BY sort_order, id LIMIT 1")->execute([$id, $companyId]);
            // what is still owed: the total less credits applied and write-offs (those stay)
            $creditedC = self::creditedCents($db, $companyId, $id);
            $writeOffC = (int)round((float)($inv['write_off_amount'] ?? 0) * 100);
            $balanceC = max(0, (int)round((float)$inv['total'] * 100) - $creditedC - $writeOffC);
            $status = $balanceC <= 0 ? ($writeOffC > 0 ? 'written_off' : 'paid') : ($creditedC + $writeOffC > 0 ? 'part-paid' : 'sent');
            if (!in_array($inv['status'], ['paid', 'part-paid', 'refunded'], true)) $status = $balanceC > 0 ? $inv['status'] : $status;
            $db->prepare("UPDATE invoices SET balance_due = ?, status = ?, paid_at = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
               ->execute([$balanceC / 100, $status, $balanceC <= 0 ? $inv['paid_at'] : null, $id, $companyId]);
            InvoiceDeleteHelper::log($db, $companyId, $userId, 'invoice_payments_unapplied', ['invoice_id' => $id, 'amount_detached' => $total]);
            QiActivity::log($db, $companyId, 'invoice', $id, 'unapplied', 'Payments of ' . QiMoney::format($total, self::sym($inv)) . ' removed (kept on the customer\'s account)', $userId);
            $db->commit();
        } catch (Throwable $e) {
            self::rollback($db);
            throw $e;
        }
        self::after($db, $companyId, $userId, 'invoice', $id);
        return ['balance' => $balanceC / 100, 'status' => $status];
    }

    /**
     * Cancel an issued invoice with a credit note for everything that is still
     * creditable: created, approved (posted) and applied in one step.
     */
    public static function creditInFull(PDO $db, int $companyId, int $userId, int $id, string $reason): array
    {
        $reason = QiWriter::text($reason, 2000, true);
        if (mb_strlen($reason) < 3) throw new QiInvalid('Say briefly why the invoice is credited — SARS requires the reason on the credit note.', 'reason');
        $inv = QiWriter::lockRow($db, $companyId, 'invoice', $id);
        if (!$inv) throw new QiInvalid('Invoice not found', '', 404);
        $st = $db->prepare("SELECT COUNT(*) FROM credit_notes WHERE invoice_id = ? AND company_id = ? AND status <> 'cancelled'");
        $st->execute([$id, $companyId]);
        if ((int)$st->fetchColumn() > 0) throw new QiInvalid('This invoice already has a credit note. Create another one from the credit note editor to credit the rest.');
        require_once __DIR__ . '/QiEditor.php';
        $copy = QiEditor::fromRow($db, $companyId, 'invoice', $inv, true);
        $rows = [];
        foreach ($copy['rows'] as $r) {
            if ($r['kind'] !== 'item') continue;
            $r['src'] = $r['ref'];
            unset($r['ref']);
            $rows[] = $r;
        }
        $saved = QiWriter::save($db, $companyId, $userId, 'credit_note', [
            'invoice_id' => $id, 'issue_date' => date('Y-m-d'), 'reason' => $reason, 'reason_code' => 'cancellation', 'rows' => $rows,
        ]);
        self::approveCredit($db, $companyId, $userId, (int)$saved['id']);
        $applied = self::applyCredit($db, $companyId, $userId, (int)$saved['id'], $id);
        return ['credit_note_id' => (int)$saved['id'], 'credit_note_number' => $saved['number'], 'applied' => $applied['applied'], 'unapplied' => $applied['unapplied']];
    }

    // ================================================================ credit notes

    /** Approve: the credit note is issued and posted to the ledger (VAT is reduced on its date). */
    public static function approveCredit(PDO $db, int $companyId, int $userId, int $id): array
    {
        $db->beginTransaction();
        try {
            $cn = self::lock($db, $companyId, 'credit_note', $id);
            if ($cn['status'] !== 'draft') throw new QiInvalid('Only a draft credit note can be approved.');
            if (!empty($cn['invoice_id'])) {
                // s21: re-check what is creditable at approval
                $st = $db->prepare("SELECT total, status, deleted_at, invoice_number FROM invoices WHERE id = ? AND company_id = ? FOR UPDATE");
                $st->execute([(int)$cn['invoice_id'], $companyId]);
                $inv = $st->fetch(PDO::FETCH_ASSOC);
                if (!$inv || !empty($inv['deleted_at']) || in_array($inv['status'], ['draft', 'cancelled'], true)) {
                    throw new QiInvalid('The invoice this credit note is for is no longer issued.');
                }
                $st = $db->prepare("SELECT COALESCE(SUM(total), 0) FROM credit_notes WHERE invoice_id = ? AND company_id = ? AND status <> 'cancelled' AND id <> ?");
                $st->execute([(int)$cn['invoice_id'], $companyId, $id]);
                if ((int)round((float)$cn['total'] * 100) > (int)round(((float)$inv['total'] - (float)$st->fetchColumn()) * 100)) {
                    throw new QiInvalid('This credit note is now more than what can still be credited on ' . $inv['invoice_number'] . '. Edit it first.');
                }
            }
            $db->prepare("UPDATE credit_notes SET status = 'approved', updated_at = NOW() WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
            if (empty($cn['journal_id'])) (new PostingService($db, $companyId, $userId))->postCreditNote($id);
            InvoiceDeleteHelper::log($db, $companyId, $userId, 'credit_note_approved', ['credit_note_id' => $id, 'credit_note_number' => $cn['credit_note_number']]);
            QiActivity::log($db, $companyId, 'credit_note', $id, 'approved', null, $userId);
            $db->commit();
        } catch (Throwable $e) {
            self::rollback($db);
            throw $e;
        }
        self::after($db, $companyId, $userId, 'credit_note', $id);
        return ['status' => 'approved'];
    }

    /** Apply an approved credit to an invoice of the same customer (its own invoice by default). */
    public static function applyCredit(PDO $db, int $companyId, int $userId, int $id, ?int $invoiceId = null): array
    {
        $db->beginTransaction();
        try {
            $cn = self::lock($db, $companyId, 'credit_note', $id);
            if ($cn['status'] !== 'approved') throw new QiInvalid($cn['status'] === 'draft' ? 'Approve the credit note before applying it.' : 'This credit note has nothing left to apply.');
            $target = $invoiceId ?: (int)$cn['invoice_id'];
            if (!$target) throw new QiInvalid('Choose the invoice to apply this credit to.', 'invoice');
            $inv = self::lock($db, $companyId, 'invoice', $target);
            if (!empty($inv['deleted_at']) || in_array($inv['status'], ['draft', 'cancelled'], true)) {
                throw new QiInvalid('Credit can only be applied to an issued invoice.', 'invoice');
            }
            if ((int)$inv['customer_id'] !== (int)$cn['customer_id']) throw new QiInvalid('That invoice belongs to a different customer.', 'invoice');
            if (strtoupper($inv['currency'] ?: 'ZAR') !== strtoupper($cn['currency'] ?: 'ZAR')) {
                throw new QiInvalid('The credit note is in ' . strtoupper($cn['currency'] ?: 'ZAR') . ' but the invoice is in ' . strtoupper($inv['currency'] ?: 'ZAR') . '.', 'invoice');
            }
            $st = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM credit_note_allocations WHERE credit_note_id = ? AND company_id = ?");
            $st->execute([$id, $companyId]);
            $remainingC = (int)round(((float)$cn['total'] - (float)$st->fetchColumn()) * 100);
            if ($remainingC <= 0) throw new QiInvalid('This credit note has already been applied in full.');
            $balanceC = (int)round((float)$inv['balance_due'] * 100);
            if ($balanceC <= 0) throw new QiInvalid('Invoice ' . $inv['invoice_number'] . ' has nothing owing to credit.', 'invoice');
            $appliedC = min($remainingC, $balanceC);
            $newBalanceC = $balanceC - $appliedC;
            $status = $newBalanceC <= 0 ? 'paid' : 'part-paid';
            $db->prepare("UPDATE invoices SET balance_due = ?, status = ?, paid_at = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
               ->execute([$newBalanceC / 100, $status, $newBalanceC <= 0 ? date('Y-m-d H:i:s') : $inv['paid_at'], $target, $companyId]);
            $fully = $remainingC - $appliedC <= 0;
            $db->prepare("UPDATE credit_notes SET status = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")->execute([$fully ? 'applied' : 'approved', $id, $companyId]);
            $db->prepare("INSERT INTO credit_note_allocations (company_id, credit_note_id, invoice_id, amount) VALUES (?, ?, ?, ?)")->execute([$companyId, $id, $target, $appliedC / 100]);
            // credit notes approved before the credit was posted at approval get their journal now
            if (empty($cn['journal_id'])) (new PostingService($db, $companyId, $userId))->postCreditNote($id);
            InvoiceDeleteHelper::log($db, $companyId, $userId, 'credit_note_applied', ['credit_note_id' => $id, 'applied_amount' => $appliedC / 100, 'invoice_id' => $target]);
            $sym = self::sym($inv);
            QiActivity::log($db, $companyId, 'credit_note', $id, 'credit', QiMoney::format($appliedC / 100, $sym) . ' to ' . $inv['invoice_number'], $userId);
            QiActivity::log($db, $companyId, 'invoice', $target, 'credit', QiMoney::format($appliedC / 100, $sym) . ' from ' . $cn['credit_note_number'], $userId);
            $db->commit();
        } catch (Throwable $e) {
            self::rollback($db);
            throw $e;
        }
        self::after($db, $companyId, $userId, 'invoice', $target);
        self::after($db, $companyId, $userId, 'credit_note', $id);
        return ['applied' => $appliedC / 100, 'unapplied' => max(0, $remainingC - $appliedC) / 100, 'invoice_status' => $status];
    }

    /** Take the credit back off the invoices it was applied to (the credit note stays approved). */
    public static function unapplyCredit(PDO $db, int $companyId, int $userId, int $id): array
    {
        $db->beginTransaction();
        try {
            $cn = self::lock($db, $companyId, 'credit_note', $id);
            $st = $db->prepare("SELECT id, invoice_id, amount FROM credit_note_allocations WHERE credit_note_id = ? AND company_id = ?");
            $st->execute([$id, $companyId]);
            $allocs = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$allocs) throw new QiInvalid('This credit note has not been applied to an invoice.');
            $touched = [];
            foreach ($allocs as $a) {
                $inv = self::lock($db, $companyId, 'invoice', (int)$a['invoice_id']);
                $balanceC = (int)round((float)$inv['balance_due'] * 100) + (int)round((float)$a['amount'] * 100);
                $settledC = self::settledCents($db, $companyId, (int)$inv['id']) - (int)round((float)$a['amount'] * 100);
                $status = in_array($inv['status'], ['paid', 'part-paid'], true) ? ($settledC > 0 ? 'part-paid' : 'sent') : $inv['status'];
                $db->prepare("UPDATE invoices SET balance_due = ?, status = ?, paid_at = NULL, updated_at = NOW() WHERE id = ? AND company_id = ?")
                   ->execute([$balanceC / 100, $status, (int)$inv['id'], $companyId]);
                $db->prepare("DELETE FROM credit_note_allocations WHERE id = ?")->execute([(int)$a['id']]);
                QiActivity::log($db, $companyId, 'invoice', (int)$inv['id'], 'unapplied', 'Credit ' . $cn['credit_note_number'] . ' removed', $userId);
                $touched[] = (int)$inv['id'];
            }
            $db->prepare("UPDATE credit_notes SET status = 'approved', updated_at = NOW() WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
            InvoiceDeleteHelper::log($db, $companyId, $userId, 'credit_note_unapplied', ['credit_note_id' => $id]);
            QiActivity::log($db, $companyId, 'credit_note', $id, 'unapplied', null, $userId);
            $db->commit();
        } catch (Throwable $e) {
            self::rollback($db);
            throw $e;
        }
        foreach ($touched as $t) self::after($db, $companyId, $userId, 'invoice', $t);
        self::after($db, $companyId, $userId, 'credit_note', $id);
        return ['status' => 'approved'];
    }

    /** Cancel: a draft simply closes; an approved credit note leaves the ledger. Applied credit must be unapplied first. */
    public static function cancelCredit(PDO $db, int $companyId, int $userId, int $id, string $reason = ''): array
    {
        $db->beginTransaction();
        try {
            $cn = self::lock($db, $companyId, 'credit_note', $id);
            if ($cn['status'] === 'cancelled') throw new QiInvalid('This credit note is already cancelled.');
            $st = $db->prepare("SELECT COUNT(*) FROM credit_note_allocations WHERE credit_note_id = ? AND company_id = ?");
            $st->execute([$id, $companyId]);
            if ((int)$st->fetchColumn() > 0) throw new QiInvalid('Remove the credit from its invoice first (Unapply), then cancel.');
            $db->prepare("UPDATE credit_notes SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
            if (!empty($cn['journal_id'])) (new PostingService($db, $companyId, $userId))->unpostCreditNote($id, 'Credit note cancelled');
            InvoiceDeleteHelper::log($db, $companyId, $userId, 'credit_note_cancelled', ['credit_note_id' => $id]);
            QiActivity::log($db, $companyId, 'credit_note', $id, 'cancelled', QiWriter::text($reason, 255) ?: null, $userId);
            $db->commit();
        } catch (Throwable $e) {
            self::rollback($db);
            throw $e;
        }
        self::after($db, $companyId, $userId, 'credit_note', $id);
        return ['status' => 'cancelled'];
    }

    public static function deleteCredit(PDO $db, int $companyId, int $userId, int $id): array
    {
        $db->beginTransaction();
        try {
            $cn = self::lock($db, $companyId, 'credit_note', $id);
            if ($cn['status'] !== 'draft' || !empty($cn['journal_id'])) throw new QiInvalid('Only a draft credit note can be deleted. Cancel it instead.');
            $db->prepare("DELETE FROM credit_note_lines WHERE credit_note_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM credit_notes WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
            InvoiceDeleteHelper::log($db, $companyId, $userId, 'credit_note_deleted', ['credit_note_id' => $id, 'number' => $cn['credit_note_number']]);
            $db->commit();
        } catch (Throwable $e) {
            self::rollback($db);
            throw $e;
        }
        try {
            require_once __DIR__ . '/../services/DocumentPdfService.php';
            DocumentPdfService::removeFromDrive($db, $companyId, 'credit_note', (int)$cn['customer_id'], (string)$cn['credit_note_number']);
        } catch (Throwable $e) {
            error_log('[qi] delete credit note cleanup: ' . $e->getMessage());
        }
        return ['deleted' => true];
    }

    // ================================================================ recurring

    public static function deleteRecurring(PDO $db, int $companyId, int $userId, int $id): array
    {
        $db->beginTransaction();
        try {
            $rec = self::lock($db, $companyId, 'recurring', $id);
            $db->prepare("DELETE FROM recurring_invoice_lines WHERE recurring_invoice_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM recurring_invoices WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
            InvoiceDeleteHelper::log($db, $companyId, $userId, 'recurring_invoice_deleted', ['recurring_id' => $id, 'name' => $rec['template_name']]);
            $db->commit();
        } catch (Throwable $e) {
            self::rollback($db);
            throw $e;
        }
        return ['deleted' => true];
    }

    // ================================================================ helpers

    private static function lock(PDO $db, int $companyId, string $type, int $id): array
    {
        $row = QiWriter::lockRow($db, $companyId, $type, $id);
        if (!$row) throw new QiInvalid(ucfirst(str_replace('_', ' ', $type === 'recurring' ? 'recurring invoice' : $type)) . ' not found', '', 404);
        return $row;
    }

    private static function rollback(PDO $db): void
    {
        if ($db->inTransaction()) $db->rollBack();
    }

    private static function sym(array $doc): string
    {
        require_once __DIR__ . '/Currencies.php';
        return Currencies::symbol($doc['currency'] ?: 'ZAR');
    }

    private static function quoteInvoice(PDO $db, int $companyId, int $quoteId): ?array
    {
        $st = $db->prepare("SELECT id, invoice_number FROM invoices WHERE quote_id = ? AND company_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1");
        $st->execute([$quoteId, $companyId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function quoteResult(PDO $db, int $companyId, int $quoteId): array
    {
        $inv = self::quoteInvoice($db, $companyId, $quoteId);
        return ['status' => $inv ? 'converted' : 'accepted', 'invoice_id' => $inv ? (int)$inv['id'] : null, 'invoice_number' => $inv['invoice_number'] ?? null, 'convert_error' => null];
    }

    private static function creditedCents(PDO $db, int $companyId, int $invoiceId): int
    {
        $st = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM credit_note_allocations WHERE invoice_id = ? AND company_id = ?");
        $st->execute([$invoiceId, $companyId]);
        return (int)round((float)$st->fetchColumn() * 100);
    }

    /** Payments + applied credits on an invoice, in cents. */
    private static function settledCents(PDO $db, int $companyId, int $invoiceId): int
    {
        $st = $db->prepare("SELECT COALESCE(SUM(pa.amount), 0) FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id AND p.company_id = ? WHERE pa.invoice_id = ?");
        $st->execute([$companyId, $invoiceId]);
        return (int)round((float)$st->fetchColumn() * 100) + self::creditedCents($db, $companyId, $invoiceId);
    }

    /** Payments, credits and write-offs each credit AR in their own journal; reversing only the invoice's would strand them. */
    private static function assertNothingAllocated(PDO $db, int $companyId, array $inv, string $what): void
    {
        $id = (int)$inv['id'];
        $st = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM payment_allocations WHERE invoice_id = ?");
        $st->execute([$id]);
        if (abs((float)$st->fetchColumn()) > 0.004) throw new QiInvalid('Payments are recorded against this invoice. Remove them (Unapply payments) before you ' . $what . ' it.');
        if (self::creditedCents($db, $companyId, $id) > 0) throw new QiInvalid('A credit note is applied to this invoice. Unapply it before you ' . $what . ' it.');
        if ((float)($inv['write_off_amount'] ?? 0) > 0) throw new QiInvalid('Part of this invoice was written off, so it cannot be ' . ($what === 'reopen' ? 'reopened' : $what . 'd') . '.');
    }

    /** Refresh the PDF and Drive copy (and the calendar for dates) after a change. Best-effort. */
    public static function after(PDO $db, int $companyId, ?int $userId, string $type, int $id): void
    {
        try {
            require_once __DIR__ . '/../services/DocumentPdfService.php';
            DocumentPdfService::generateAndFile($db, $companyId, $type, $id, $userId);
        } catch (Throwable $e) {
            error_log('[qi] after ' . $type . ' #' . $id . ': ' . $e->getMessage());
        }
    }

    /** Tell the team when a customer answers a quote (in-app notification + email to the quote's author). */
    private static function notifyCompany(PDO $db, int $companyId, int $quoteId, string $what, string $name, bool $byCustomer, string $reason = ''): void
    {
        if (!$byCustomer) return;
        try {
            $st = $db->prepare("SELECT q.quote_number, q.total, q.currency, q.created_by, ca.name AS customer FROM quotes q
                                  LEFT JOIN crm_accounts ca ON ca.id = q.customer_id AND ca.company_id = q.company_id
                                 WHERE q.id = ? AND q.company_id = ?");
            $st->execute([$quoteId, $companyId]);
            $q = $st->fetch(PDO::FETCH_ASSOC);
            if (!$q || empty($q['created_by'])) return;
            $title = 'Quote ' . $q['quote_number'] . ' ' . $what . ' by ' . ($name !== '' ? $name : ($q['customer'] ?: 'the customer'));
            $body = ($q['customer'] ?: 'The customer') . ' ' . $what . ' quote ' . $q['quote_number'] . ' (' . QiMoney::format((float)$q['total'], self::sym($q)) . ')'
                  . ($reason !== '' ? ': ' . $reason : '.');
            if (function_exists('fw_notify')) {
                fw_notify($db, (int)$companyId, (int)$q['created_by'], 'qi_quote_' . $what, $title, $body, '/qi/view.php?type=quote&id=' . $quoteId);
            }
        } catch (Throwable $e) {
            error_log('[qi] notify quote ' . $what . ': ' . $e->getMessage());
        }
    }
}
