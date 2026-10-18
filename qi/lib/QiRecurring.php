<?php
/**
 * QiRecurring — turns a recurring invoice template into a real invoice. One
 * code path for the daily cron (qi/cron/generate_recurring.php) and "Generate
 * now" on the template's page.
 *
 *  - one invoice per run: a template that fell behind (paused, cron down) is
 *    moved to its next future date instead of billing every missed period;
 *  - months keep their day: the 31st bills on the last day of shorter months
 *    and returns to the 31st afterwards (anchor_day);
 *  - the template's own currency, priced at the rate of the invoice date;
 *  - the end date is honoured — the template stops after its last invoice;
 *  - the invoice is issued (posted to the ledger) and, when the template asks
 *    for it, emailed with its PDF from the template owner's mailbox.
 */

require_once __DIR__ . '/QiMoney.php';
require_once __DIR__ . '/QiActivity.php';
require_once __DIR__ . '/LineHeadings.php';
require_once __DIR__ . '/Currencies.php';
require_once __DIR__ . '/SequenceAllocator.php';
require_once __DIR__ . '/InvoiceLifecycle.php';

final class QiRecurring
{
    public const UNITS = ['weekly' => 'week', 'monthly' => 'month', 'quarterly' => 'quarter', 'yearly' => 'year'];

    /** The date after $from for this schedule (month ends clamp to the anchor day). */
    public static function nextDate(string $from, string $frequency, int $interval, ?int $anchorDay = null): string
    {
        $d = new DateTimeImmutable($from);
        $interval = max(1, $interval);
        if ($frequency === 'weekly') return $d->modify('+' . (7 * $interval) . ' days')->format('Y-m-d');
        $months = match ($frequency) { 'quarterly' => 3 * $interval, 'yearly' => 12 * $interval, default => $interval };
        $y = (int)$d->format('Y');
        $m = (int)$d->format('n') + $months;
        $y += intdiv($m - 1, 12);
        $m = (($m - 1) % 12) + 1;
        $day = $anchorDay && $anchorDay >= 1 && $anchorDay <= 31 ? $anchorDay : (int)$d->format('j');
        $last = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t');
        return sprintf('%04d-%02d-%02d', $y, $m, min($day, $last));
    }

    /** The first scheduled date on or after $today (for resuming without a backlog). */
    public static function catchUp(string $next, string $frequency, int $interval, ?int $anchorDay, string $today): string
    {
        for ($guard = 0; $next < $today && $guard < 2000; $guard++) {
            $next = self::nextDate($next, $frequency, $interval, $anchorDay);
        }
        return $next;
    }

    /**
     * Generate the template's next invoice now. Throws on failure (nothing is
     * created; the schedule does not move).
     * @return array{invoice_id:int, invoice_number:string, next_run_date:?string, ended:bool, emailed:bool, email_error:?string}
     */
    public static function generate(PDO $db, int $companyId, int $recurringId, ?int $userId, bool $manual): array
    {
        try { LineHeadings::ensureTable($db); } catch (Throwable $e) { /* headings optional */ }
        $today = date('Y-m-d');
        $db->beginTransaction();
        try {
            $st = $db->prepare("SELECT * FROM recurring_invoices WHERE id = ? AND company_id = ? FOR UPDATE");
            $st->execute([$recurringId, $companyId]);
            $rec = $st->fetch(PDO::FETCH_ASSOC);
            if (!$rec) throw new RuntimeException('Recurring invoice not found');
            if (empty($rec['active'])) throw new RuntimeException('This recurring invoice is paused. Resume it first.');
            $end = !empty($rec['end_date']) && $rec['end_date'] > '1000-01-01' ? (string)$rec['end_date'] : null;
            if (!$manual) {
                // cron: re-check under the lock — a concurrent run may have generated it already
                if ((string)$rec['next_run_date'] > $today) throw new RuntimeException('Not due yet');
            }
            // the end date is the last day an invoice may fall due: a period that was due
            // before it is still billed (even when the run comes late), nothing after it
            if ($end !== null && (string)$rec['next_run_date'] > $end) {
                $db->prepare("UPDATE recurring_invoices SET active = 0, updated_at = NOW() WHERE id = ?")->execute([$recurringId]);
                $db->commit();
                throw new RuntimeException('This recurring invoice ended on ' . $end . '.');
            }

            $st = $db->prepare("SELECT * FROM recurring_invoice_lines WHERE recurring_invoice_id = ? ORDER BY sort_order, id");
            $st->execute([$recurringId]);
            $lines = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$lines) throw new RuntimeException('This recurring invoice has no lines.');
            $tpl = json_decode((string)($rec['template_data'] ?? ''), true);
            $tpl = is_array($tpl) ? $tpl : [];

            $st = $db->prepare("SELECT * FROM qi_settings WHERE company_id = ?");
            $st->execute([$companyId]);
            $settings = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $issueDate = $today;
            $terms = $rec['payment_terms_days'] !== null ? (int)$rec['payment_terms_days'] : (int)($settings['default_payment_terms'] ?? 30);
            $dueDate = date('Y-m-d', strtotime($issueDate . ' +' . max(0, $terms) . ' days'));

            $currency = Currencies::isValid($rec['currency'] ?? null) ? strtoupper($rec['currency'])
                : (Currencies::isValid($settings['default_currency'] ?? null) ? strtoupper($settings['default_currency']) : Currencies::BASE);
            $rate = 1.0;
            if ($currency !== Currencies::BASE) {
                require_once __DIR__ . '/../../finances/lib/CurrencyService.php';
                $rate = (float)((new CurrencyService($db, $companyId))->getRate($currency, $issueDate) ?? 0);
                if ($rate <= 0) {
                    // never book a foreign invoice at parity; the run retries once a rate exists
                    throw new RuntimeException("No {$currency} exchange rate for {$issueDate}. Add one under Finances → Exchange rates.");
                }
            }

            $contactId = !empty($tpl['contact_id']) ? (int)$tpl['contact_id'] : null;
            if ($contactId) {
                $st = $db->prepare("SELECT id FROM crm_contacts WHERE id = ? AND account_id = ? AND company_id = ?");
                $st->execute([$contactId, (int)$rec['customer_id'], $companyId]);
                if (!$st->fetchColumn()) $contactId = null;
            }
            $projectId = !empty($tpl['project_id']) ? (int)$tpl['project_id'] : null;

            $t = QiMoney::totals($lines);
            [$number] = (new SequenceAllocator($db))->allocate($companyId, 'invoice', $issueDate, true);
            $owner = $userId ?: ((int)$rec['created_by'] ?: null);
            $db->prepare("INSERT INTO invoices (company_id, invoice_number, customer_id, contact_id, project_id, issue_date, due_date, status,
                                                subtotal, discount, tax, total, balance_due, currency, exchange_rate, terms, notes, reference,
                                                customer_notes, recurring_invoice_id, public_token, created_by, created_at, updated_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
               ->execute([
                   $companyId, $number, (int)$rec['customer_id'], $contactId, $projectId, $issueDate, $dueDate,
                   $t['net'] / 100, $t['discount'] / 100, $t['vat'] / 100, $t['total'] / 100, $t['total'] / 100,
                   $currency, $rate, (string)($rec['terms'] ?? ''),
                   'Generated from recurring invoice: ' . ($rec['template_name'] ?: '#' . $recurringId),
                   ($tpl['reference'] ?? '') !== '' ? (string)$tpl['reference'] : null,
                   ($tpl['customer_notes'] ?? '') !== '' ? (string)$tpl['customer_notes'] : null,
                   $recurringId, bin2hex(random_bytes(16)), $owner ?: 1,
               ]);
            $invoiceId = (int)$db->lastInsertId();

            $ins = $db->prepare("INSERT INTO invoice_lines (invoice_id, item_description, quantity, unit, unit_price, discount, tax_rate, tax_code_id, line_total, sort_order)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($lines as $l) {
                $m = QiMoney::line($l['quantity'], $l['unit_price'], $l['discount'], $l['tax_rate']);
                $ins->execute([$invoiceId, $l['item_description'], $l['quantity'], (string)($l['unit'] ?? ''), $l['unit_price'], $l['discount'],
                               $l['tax_rate'], $l['tax_code_id'] ?? null, $m['gross'] / 100, (int)$l['sort_order']]);
            }
            $headings = is_array($tpl['headings'] ?? null) ? $tpl['headings'] : [];
            LineHeadings::replace($db, $companyId, LineHeadings::TYPE_INVOICE, $invoiceId, $headings);

            // the schedule moves on from the date that was due; a run by hand ahead of time
            // is that date's invoice, sent early (the next one is a full period later)
            $anchor = $rec['anchor_day'] !== null ? (int)$rec['anchor_day'] : (int)date('j', strtotime((string)$rec['next_run_date']));
            $base = (string)$rec['next_run_date'];
            $next = self::catchUp(self::nextDate($base, (string)$rec['frequency'], (int)$rec['interval_count'], $anchor),
                                  (string)$rec['frequency'], (int)$rec['interval_count'], $anchor, date('Y-m-d', strtotime($today . ' +1 day')));
            $ended = $end !== null && $next > $end;
            $db->prepare("UPDATE recurring_invoices SET next_run_date = ?, last_generated_date = ?, last_invoice_id = ?, active = ?, updated_at = NOW()
                           WHERE id = ? AND company_id = ?")
               ->execute([$next, $today, $invoiceId, $ended ? 0 : 1, $recurringId, $companyId]);

            // A generated recurring invoice is an intentional issuance: out of
            // draft and into the ledger inside this transaction (the whole
            // generation rolls back if posting fails).
            InvoiceLifecycle::issueInvoice($db, $companyId, (int)($owner ?: 1), $invoiceId);

            QiActivity::log($db, $companyId, 'recurring', $recurringId, 'generated', $number . ($ended ? ' — last invoice' : ''), $userId, $userId ? null : 'Schedule');
            QiActivity::log($db, $companyId, 'invoice', $invoiceId, 'created', 'From recurring invoice ' . ($rec['template_name'] ?: '#' . $recurringId), $userId, $userId ? null : 'Schedule');
            QiActivity::log($db, $companyId, 'invoice', $invoiceId, 'issued', null, $userId, $userId ? null : 'Schedule');
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        try {
            require_once __DIR__ . '/../services/DocumentPdfService.php';
            DocumentPdfService::generateAndFile($db, $companyId, 'invoice', $invoiceId, $owner);
        } catch (Throwable $e) {
            error_log('[qi] recurring pdf: ' . $e->getMessage());
        }

        $emailed = false;
        $emailError = null;
        if (!empty($rec['auto_send'])) {
            try {
                require_once __DIR__ . '/../services/QiMail.php';
                $r = QiMail::sendDocument($db, $companyId, (int)($owner ?: 0), 'invoice', $invoiceId, []);
                $emailed = $r['ok'];
                $emailError = $r['ok'] ? null : $r['error'];
            } catch (Throwable $e) {
                $emailError = $e->getMessage();
            }
        }
        return ['invoice_id' => $invoiceId, 'invoice_number' => $number, 'next_run_date' => $ended ? null : $next, 'ended' => $ended,
                'emailed' => $emailed, 'email_error' => $emailError];
    }

    /**
     * Generate every template that is due (cron and the background tick), oldest
     * first, across companies. One template failing (no exchange rate, an invalid
     * tax invoice) never stops the others: it is tried again at most once an
     * hour (sooner when someone edits it or presses "Generate now"), so a pile
     * of failing templates cannot hold up the ones behind them, and the failure
     * is put on the template's timeline once a day so someone sees it.
     * 'more' says that due templates were left for the next run.
     * @return array{generated:int, failed:int, more:bool}
     */
    public static function runDue(PDO $db, int $limit = 50, ?float $deadline = null): array
    {
        $limit = max(1, min(500, $limit));
        $today = date('Y-m-d');
        $st = $db->prepare("SELECT id, company_id FROM recurring_invoices
                             WHERE active = 1 AND next_run_date <= ? AND (end_date IS NULL OR end_date < '1000-01-02' OR end_date >= next_run_date)
                               AND (last_attempt_at IS NULL OR last_attempt_at < DATE_SUB(NOW(), INTERVAL 1 HOUR))
                             ORDER BY next_run_date, id LIMIT " . $limit);
        $st->execute([$today]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $done = ['generated' => 0, 'failed' => 0, 'more' => count($rows) === $limit];
        $attempt = $db->prepare("UPDATE recurring_invoices SET last_attempt_at = NOW(), updated_at = updated_at WHERE id = ?");
        foreach ($rows as $i => $r) {
            if ($deadline !== null && $i > 0 && microtime(true) > $deadline) {   // at least one per run
                $done['more'] = true;
                break;
            }
            // stamped first, outside the generation's transaction: a run that dies
            // half-way does not retry the same template on every tick
            $attempt->execute([(int)$r['id']]);
            try {
                self::generate($db, (int)$r['company_id'], (int)$r['id'], null, false);
                $done['generated']++;
            } catch (Throwable $e) {
                if ($e->getMessage() === 'Not due yet') continue;
                $done['failed']++;
                error_log('[qi] recurring #' . $r['id'] . ': ' . $e->getMessage());
                try {
                    $chk = $db->prepare("SELECT 1 FROM qi_activity WHERE company_id = ? AND doc_type = 'recurring' AND doc_id = ? AND action = 'generate_failed'
                                           AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 1");
                    $chk->execute([(int)$r['company_id'], (int)$r['id']]);
                    if (!$chk->fetchColumn()) {
                        QiActivity::log($db, (int)$r['company_id'], 'recurring', (int)$r['id'], 'generate_failed', mb_substr($e->getMessage(), 0, 400), null, 'Schedule');
                    }
                } catch (Throwable $x) {
                    // timeline is best-effort
                }
            }
        }
        return $done;
    }

    /** Pause or resume. Resuming moves a date that passed while paused to the next one (no backlog). */
    public static function setActive(PDO $db, int $companyId, int $recurringId, bool $active, ?int $userId): array
    {
        $db->beginTransaction();
        try {
            $st = $db->prepare("SELECT * FROM recurring_invoices WHERE id = ? AND company_id = ? FOR UPDATE");
            $st->execute([$recurringId, $companyId]);
            $rec = $st->fetch(PDO::FETCH_ASSOC);
            if (!$rec) throw new RuntimeException('Recurring invoice not found');
            $next = (string)$rec['next_run_date'];
            if ($active) {
                $today = date('Y-m-d');
                $anchor = $rec['anchor_day'] !== null ? (int)$rec['anchor_day'] : (int)date('j', strtotime($next));
                $next = self::catchUp($next, (string)$rec['frequency'], (int)$rec['interval_count'], $anchor, $today);
                $end = !empty($rec['end_date']) && $rec['end_date'] > '1000-01-01' ? (string)$rec['end_date'] : null;
                if ($end !== null && $next > $end) throw new RuntimeException('This recurring invoice has passed its end date. Change the end date to resume it.');
            }
            $db->prepare("UPDATE recurring_invoices SET active = ?, next_run_date = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
               ->execute([$active ? 1 : 0, $next, $recurringId, $companyId]);
            QiActivity::log($db, $companyId, 'recurring', $recurringId, $active ? 'resumed' : 'paused', $active ? 'Next invoice ' . $next : null, $userId);
            $db->commit();
            return ['active' => $active, 'next_run_date' => $next];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
