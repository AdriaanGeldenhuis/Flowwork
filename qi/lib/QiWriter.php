<?php
/**
 * QiWriter — the one write path behind the document editor (qi/edit.php via
 * qi/api/document.php): creates and updates quotes, invoices, credit notes
 * and recurring invoice templates.
 *
 * Nothing the browser sends is trusted:
 *   - the customer, contact, project and linked invoice must belong to the
 *     company; dates must be real dates in a sensible order;
 *   - every line is recomputed in integer cents with the ledger's rounding
 *     (QiMoney) and takes its VAT rate from the company's tax code, so the
 *     stored header always equals what PostingService posts;
 *   - line_total is the VAT-inclusive line amount in every line table;
 *   - a payment schedule must add up to the total to the cent (the last
 *     percentage row absorbs rounding).
 *
 * Edits lock the row (FOR UPDATE) and respect the document's state
 * (lockState()):
 *   quote        accepted / converted / cancelled       -> read only
 *   invoice      draft                                  -> everything
 *                issued, nothing paid or credited       -> everything; the
 *                                                         ledger entry is re-posted
 *                paid / part paid / credited / archived -> wording only (reference,
 *                                                         notes, terms, due date)
 *                void / deleted                         -> read only
 *   credit note  draft -> everything, otherwise read only
 *   recurring    always (it is a template)
 * An editor that went stale (someone saved in between) gets a conflict
 * instead of silently overwriting, unless it asks to force.
 */

require_once __DIR__ . '/QiMoney.php';
require_once __DIR__ . '/QiActivity.php';
require_once __DIR__ . '/LineHeadings.php';
require_once __DIR__ . '/Currencies.php';
require_once __DIR__ . '/SequenceAllocator.php';
require_once __DIR__ . '/../../finances/lib/TaxCodes.php';
require_once __DIR__ . '/../../projects/lib/autoload.php';

/** A validation failure the editor can point at (field = 'customer', 'rows.3.price', ...). */
final class QiInvalid extends RuntimeException
{
    public string $field;
    public array $extra;

    public function __construct(string $message, string $field = '', int $status = 422, array $extra = [])
    {
        parent::__construct($message, $status);
        $this->field = $field;
        $this->extra = $extra;
    }
}

final class QiWriter
{
    public const TYPES = ['quote', 'invoice', 'credit_note', 'recurring'];
    public const MAX_ROWS = 500;
    public const MAX_MILESTONES = 20;
    public const REASON_CODES = ['return', 'discount', 'correction', 'damaged', 'cancellation', 'vat_adjustment', 'other'];
    public const FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'yearly'];

    private const NOUN = ['quote' => 'quote', 'invoice' => 'invoice', 'credit_note' => 'credit note', 'recurring' => 'recurring invoice'];

    // ------------------------------------------------------------ settings

    /** The company's document defaults (qi_settings with fallbacks). */
    public static function defaults(PDO $db, int $companyId): array
    {
        $row = [];
        try {
            $st = $db->prepare("SELECT * FROM qi_settings WHERE company_id = ? LIMIT 1");
            $st->execute([$companyId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $row = [];
        }
        $st = $db->prepare("SELECT vat_number FROM companies WHERE id = ?");
        $st->execute([$companyId]);
        $vat = trim((string)$st->fetchColumn());
        $currency = Currencies::isValid($row['default_currency'] ?? null) ? strtoupper($row['default_currency']) : Currencies::BASE;
        return [
            'terms'          => (string)($row['default_terms'] ?? ''),
            'notes'          => (string)($row['default_notes'] ?? ''),
            'payment_terms'  => max(0, min(365, (int)($row['default_payment_terms'] ?? 30) ?: 30)),
            'quote_validity' => max(1, min(365, (int)($row['quote_validity_days'] ?? 14) ?: 14)),
            'currency'       => $currency,
            'vat_registered' => $vat !== '',
        ];
    }

    /**
     * VAT choices for a line: the company's active output / exempt codes,
     * STD first. [code => ['code','label','name','rate','tax_code_id']]
     */
    public static function taxOptions(PDO $db, int $companyId): array
    {
        $codes = new TaxCodes($db, $companyId);
        $out = [];
        foreach ($codes->all() as $row) {
            $type = strtolower((string)($row['type'] ?? ''));
            $code = strtoupper(trim((string)$row['code']));
            if (!in_array($type, ['output', 'zero', 'exempt'], true) || $code === 'INPUT' || isset($out[$code])) continue;
            if ($code === 'STANDARD' && isset($out['STD'])) continue;
            $rate = (float)$row['rate_percent'];
            $out[$code] = [
                'code'        => $code,
                'label'       => $code === 'EXEMPT' || $type === 'exempt' ? 'Exempt' : QiMoney::rate($rate) . '%',
                'name'        => (string)($row['description'] ?? $code),
                'rate'        => $rate,
                'tax_code_id' => (int)$row['tax_code_id'],
            ];
        }
        uksort($out, function ($a, $b) {
            $rank = ['STD' => 0, 'STANDARD' => 0, 'ZERO' => 1, 'EXEMPT' => 2];
            return [$rank[$a] ?? 3, $a] <=> [$rank[$b] ?? 3, $b];
        });
        return $out;
    }

    /** The VAT code a new line starts with: standard rate for VAT vendors, none otherwise. */
    public static function defaultTaxCode(array $taxOptions, bool $vatRegistered): string
    {
        if ($vatRegistered) {
            foreach ($taxOptions as $code => $o) if ($o['rate'] > 0) return $code;
        }
        if (isset($taxOptions['ZERO'])) return 'ZERO';
        return (string)(array_key_first($taxOptions) ?? 'STD');
    }

    // ------------------------------------------------------------ locks

    /**
     * What may still change on a stored document.
     * @return array{level:string, reason:string, repost:bool} level: none | wording | full
     */
    public static function lockState(PDO $db, int $companyId, string $type, array $row): array
    {
        $none = ['level' => 'none', 'reason' => '', 'repost' => false];
        $status = (string)($row['status'] ?? 'draft');
        if ($type === 'quote') {
            if (in_array($status, ['accepted', 'converted'], true)) {
                return ['level' => 'full', 'reason' => 'This quote has been accepted, so it can no longer be changed. Duplicate it to make a new version.', 'repost' => false];
            }
            if ($status === 'cancelled') {
                return ['level' => 'full', 'reason' => 'This quote was cancelled. Duplicate it to start again.', 'repost' => false];
            }
            return $none;
        }
        if ($type === 'invoice') {
            if (!empty($row['deleted_at'])) {
                return ['level' => 'full', 'reason' => 'This invoice was deleted. Restore it before making changes.', 'repost' => false];
            }
            if ($status === 'cancelled') {
                return ['level' => 'full', 'reason' => 'This invoice was voided. Reopen it before making changes.', 'repost' => false];
            }
            $id = (int)$row['id'];
            $st = $db->prepare("SELECT COUNT(*) FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id AND p.company_id = ? WHERE pa.invoice_id = ?");
            $st->execute([$companyId, $id]);
            $paid = (int)$st->fetchColumn();
            $credited = 0;
            try {
                $st = $db->prepare("SELECT COUNT(*) FROM credit_note_allocations WHERE invoice_id = ? AND company_id = ?");
                $st->execute([$id, $companyId]);
                $credited = (int)$st->fetchColumn();
            } catch (Throwable $e) {
                $credited = 0;
            }
            if ($paid > 0 || $credited > 0 || !empty($row['archived_at'])
                || in_array($status, ['paid', 'part-paid', 'written_off', 'refunded', 'uncollectible'], true)) {
                return ['level' => 'wording', 'reason' => 'Payments or credits are recorded against this invoice, so its amounts are locked. You can still change the reference, notes, terms' . ($status === 'paid' ? '' : ' and due date') . '.', 'repost' => false];
            }
            return ['level' => 'none', 'reason' => '', 'repost' => $status !== 'draft' && !empty($row['journal_id'])];
        }
        if ($type === 'credit_note') {
            if ($status !== 'draft') {
                return ['level' => 'full', 'reason' => 'This credit note has been ' . ($status === 'cancelled' ? 'cancelled' : 'approved') . ', so it can no longer be changed.', 'repost' => false];
            }
            return $none;
        }
        return $none;
    }

    // ------------------------------------------------------------ normalise

    /**
     * Validate and recompute an editor payload. With $strict = false (live
     * preview) problems are tolerated and filled with defaults instead.
     */
    public static function normalize(PDO $db, int $companyId, string $type, array $in, ?array $existing = null, bool $strict = true): array
    {
        if (!in_array($type, self::TYPES, true)) throw new QiInvalid('Unknown document type');
        $noun = self::NOUN[$type];
        $defaults = self::defaults($db, $companyId);
        $taxOptions = self::taxOptions($db, $companyId);
        $defaultCode = self::defaultTaxCode($taxOptions, $defaults['vat_registered']);
        $fail = function (string $msg, string $field) use ($strict) {
            if ($strict) throw new QiInvalid($msg, $field);
        };
        $d = ['type' => $type];

        // ---- linked invoice (credit notes): fixes customer and currency
        $invoice = null;
        if ($type === 'credit_note') {
            $invoiceId = self::intOf($in['invoice_id'] ?? 0);
            if ($invoiceId > 0) {
                $st = $db->prepare("SELECT id, invoice_number, customer_id, status, deleted_at, currency, exchange_rate, total, issue_date
                                      FROM invoices WHERE id = ? AND company_id = ?");
                $st->execute([$invoiceId, $companyId]);
                $invoice = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$invoice) {
                    $fail('That invoice was not found.', 'invoice');
                } elseif ($invoice['status'] === 'draft') {
                    $fail('Invoice ' . $invoice['invoice_number'] . ' is still a draft — change the invoice itself instead of crediting it.', 'invoice');
                    $invoice = null;
                } elseif (!empty($invoice['deleted_at']) || $invoice['status'] === 'cancelled') {
                    $fail('Invoice ' . $invoice['invoice_number'] . ' was voided or deleted, so it cannot be credited.', 'invoice');
                    $invoice = null;
                }
            }
            $d['invoice_id'] = $invoice ? (int)$invoice['id'] : null;
            $d['invoice'] = $invoice;
        }

        // ---- customer + contact
        $customerId = $invoice ? (int)$invoice['customer_id'] : self::intOf($in['customer_id'] ?? 0);
        $customer = null;
        if ($customerId > 0) {
            $st = $db->prepare("SELECT id, name, currency, deleted_at FROM crm_accounts WHERE id = ? AND company_id = ?");
            $st->execute([$customerId, $companyId]);
            $customer = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($customer && !empty($customer['deleted_at']) && (int)($existing['customer_id'] ?? 0) !== $customerId) {
                $fail($customer['name'] . ' was deleted in CRM. Choose another customer.', 'customer');
                $customer = null;
            }
        }
        if (!$customer) {
            $fail('Choose who this ' . $noun . ' is for.', 'customer');
            $customerId = 0;
        }
        $d['customer_id'] = $customer ? (int)$customer['id'] : 0;

        $contactId = in_array($type, ['quote', 'invoice', 'recurring'], true) ? self::intOf($in['contact_id'] ?? 0) : 0;
        if ($contactId > 0) {
            $st = $db->prepare("SELECT id FROM crm_contacts WHERE id = ? AND account_id = ? AND company_id = ?");
            $st->execute([$contactId, $d['customer_id'], $companyId]);
            if (!$st->fetchColumn()) {
                $fail('That contact does not belong to this customer.', 'contact');
                $contactId = 0;
            }
        }
        $d['contact_id'] = $contactId ?: null;

        // ---- project
        $projectId = in_array($type, ['quote', 'invoice', 'recurring'], true) ? self::intOf($in['project_id'] ?? 0) : 0;
        if ($projectId > 0 && !ProjectLink::belongs($db, $companyId, $projectId)) {
            $fail('That project was not found.', 'project');
            $projectId = 0;
        }
        $d['project_id'] = $projectId ?: null;

        // ---- dates
        $today = date('Y-m-d');
        if ($type === 'recurring') {
            $start = self::dateOf($in['next_run_date'] ?? '');
            if ($start === null) {
                $fail('Choose the date of the first invoice.', 'next_run_date');
                $start = $today;
            }
            $prevStart = (string)($existing['next_run_date'] ?? '');
            if ($start < $today && $start !== $prevStart) {
                $fail('The next invoice date cannot be in the past — that would bill every missed period at once.', 'next_run_date');
            }
            $end = self::dateOf($in['end_date'] ?? '');
            if ($end !== null && $end < $start) {
                $fail('The end date is before the next invoice date.', 'end_date');
                $end = null;
            }
            $d['next_run_date'] = $start;
            $d['end_date'] = $end;
            $d['issue_date'] = $start;
        } else {
            $issue = self::dateOf($in['issue_date'] ?? '');
            if ($issue === null) {
                $fail('Choose the ' . $noun . ' date.', 'issue_date');
                $issue = $today;
            }
            $d['issue_date'] = $issue;
            if ($type === 'quote') {
                $exp = self::dateOf($in['expiry_date'] ?? '') ?? date('Y-m-d', strtotime($issue . ' +' . $defaults['quote_validity'] . ' days'));
                if ($exp < $issue) {
                    $fail('The quote cannot expire before its date.', 'expiry_date');
                    $exp = $issue;
                }
                $d['expiry_date'] = $exp;
            } elseif ($type === 'invoice') {
                $due = self::dateOf($in['due_date'] ?? '') ?? date('Y-m-d', strtotime($issue . ' +' . $defaults['payment_terms'] . ' days'));
                if ($due < $issue) {
                    $fail('The due date cannot be before the invoice date.', 'due_date');
                    $due = $issue;
                }
                $d['due_date'] = $due;
            }
        }

        // ---- wording
        $d['reference'] = self::text($in['reference'] ?? '', 120);
        $d['terms'] = self::text($in['terms'] ?? '', 10000, true);
        $d['customer_notes'] = self::text($in['customer_notes'] ?? '', 5000, true);
        $d['notes'] = self::text($in['notes'] ?? '', 5000, true);

        // ---- currency
        if ($invoice) {
            $currency = Currencies::isValid($invoice['currency'] ?? null) ? strtoupper($invoice['currency']) : Currencies::BASE;
            $rate = $currency === Currencies::BASE ? 1.0 : ((float)($invoice['exchange_rate'] ?? 0) ?: 1.0);
        } else {
            $currency = strtoupper(trim((string)($in['currency'] ?? '')));
            if (!Currencies::isValid($currency)) {
                $currency = Currencies::isValid($existing['currency'] ?? null) ? strtoupper($existing['currency'])
                    : (Currencies::isValid($customer['currency'] ?? null) ? strtoupper($customer['currency']) : $defaults['currency']);
            }
            $rate = is_numeric($in['exchange_rate'] ?? null) ? round((float)$in['exchange_rate'], 6) : 0.0;
            if ($currency === Currencies::BASE) {
                $rate = 1.0;
            } elseif ($type !== 'recurring' && ($rate <= 0 || $rate > 1000000)) {
                require_once __DIR__ . '/../../finances/lib/CurrencyService.php';
                $rate = (float)((new CurrencyService($db, $companyId))->getRate($currency, $d['issue_date']) ?? 0);
                if ($rate <= 0) {
                    $fail('There is no ' . $currency . ' exchange rate for ' . $d['issue_date'] . '. Enter the rate, or add one under Finances → Exchange rates.', 'exchange_rate');
                    $rate = 1.0;
                }
            }
        }
        $d['currency'] = $currency;
        $d['exchange_rate'] = $type === 'recurring' ? null : $rate;

        // ---- lines
        $carry = $existing ? self::lineExtras($db, $type, (int)$existing['id']) : [];
        $srcCarry = $invoice ? self::lineExtras($db, 'invoice', (int)$invoice['id']) : [];
        $rowsIn = is_array($in['rows'] ?? null) ? array_values($in['rows']) : [];
        if (count($rowsIn) > self::MAX_ROWS) {
            $fail('A document can have at most ' . self::MAX_ROWS . ' lines.', 'rows');
            $rowsIn = array_slice($rowsIn, 0, self::MAX_ROWS);
        }
        $rows = [];
        $sort = 0;
        $itemNo = 0;
        foreach ($rowsIn as $i => $r) {
            if (!is_array($r)) continue;
            if (($r['kind'] ?? 'item') === 'heading') {
                $title = self::text($r['title'] ?? '', 255);
                if ($title === '' || $type === 'credit_note') continue;
                $rows[] = ['kind' => 'heading', 'title' => $title, 'sort' => $sort++];
                continue;
            }
            $desc = self::text($r['description'] ?? '', 5000, true);
            $qty = self::numOf($r['qty'] ?? 1, 3);
            $price = self::numOf($r['price'] ?? 0, 2);
            $disc = self::numOf($r['discount'] ?? 0, 2);
            if ($desc === '' && abs($price) < 0.005 && abs($disc) < 0.005) continue; // an untouched empty line
            $itemNo++;
            $where = 'Line ' . $itemNo;
            if ($desc === '') $fail($where . ' needs a description.', "rows.$i.description");
            if ($qty === null || abs($qty) > 1e9) { $fail($where . ': enter a valid quantity.', "rows.$i.qty"); $qty = 1.0; }
            if ($price === null || abs($price) > 1e11) { $fail($where . ': enter a valid price.', "rows.$i.price"); $price = 0.0; }
            if ($disc === null || $disc < 0) { $fail($where . ': the discount cannot be negative.', "rows.$i.discount"); $disc = 0.0; }
            $beforeC = (int)round($qty * $price * 100);
            if ($disc > 0 && (int)round($disc * 100) > abs($beforeC)) {
                $fail($where . ': the discount is more than the line amount.', "rows.$i.discount");
                $disc = abs($beforeC) / 100;
            }
            $code = strtoupper(trim((string)($r['tax_code'] ?? '')));
            if (!isset($taxOptions[$code])) {
                if ($code !== '') $fail($where . ': choose a VAT rate.', "rows.$i.tax_code");
                $code = $defaultCode;
            }
            $opt = $taxOptions[$code] ?? ['rate' => 0.0, 'tax_code_id' => null];
            $m = QiMoney::line($qty, $price, $disc, $opt['rate']);
            $extra = $carry[self::intOf($r['ref'] ?? 0)] ?? ($srcCarry[self::intOf($r['src'] ?? 0)] ?? []);
            $rows[] = [
                'kind'        => 'item',
                'description' => $desc,
                'qty'         => $qty,
                'unit'        => self::text($r['unit'] ?? '', 50),
                'price'       => $price,
                'discount'    => $disc,
                'tax_code'    => $code,
                'tax_code_id' => $opt['tax_code_id'] ? (int)$opt['tax_code_id'] : null,
                'rate'        => (float)$opt['rate'],
                'net_c'       => $m['net'],
                'vat_c'       => $m['vat'],
                'gross_c'     => $m['gross'],
                'gl_account_id'     => $extra['gl_account_id'] ?? null,
                'inventory_item_id' => $extra['inventory_item_id'] ?? null,
                'project_item_id'   => $extra['project_item_id'] ?? null,
                'project_board_id'  => $extra['project_board_id'] ?? null,
                'sort'        => $sort++,
            ];
        }
        if ($itemNo === 0) $fail('Add at least one line.', 'rows');
        $d['rows'] = $rows;

        $t = ['before' => 0, 'discount' => 0, 'net' => 0, 'vat' => 0];
        foreach ($rows as $r) {
            if ($r['kind'] !== 'item') continue;
            $m = QiMoney::line($r['qty'], $r['price'], $r['discount'], $r['rate']);
            $t['before'] += $m['before'];
            $t['discount'] += $m['discount'];
            $t['net'] += $m['net'];
            $t['vat'] += $m['vat'];
        }
        $t['total'] = $t['net'] + $t['vat'];
        if ($type === 'credit_note' ? $t['total'] <= 0 : $t['total'] < 0) {
            $fail($type === 'credit_note' ? 'A credit note must be for more than zero.' : 'The total cannot be negative.', 'rows');
        }
        $d['totals_c'] = $t;

        // ---- payment schedule
        $d['milestones'] = null;
        if (in_array($type, ['quote', 'invoice'], true) && is_array($in['milestones'] ?? null) && count($in['milestones']) > 0) {
            $d['milestones'] = self::milestones(array_values($in['milestones']), $t['total'], Currencies::symbol($currency), $strict);
        }

        // ---- type specific
        if ($type === 'credit_note') {
            $code = (string)($in['reason_code'] ?? '');
            $d['reason_code'] = in_array($code, self::REASON_CODES, true) ? $code : 'other';
            $d['reason'] = self::text($in['reason'] ?? '', 2000, true);
            if (mb_strlen($d['reason']) < 3) $fail('Say briefly why this credit note is issued — SARS requires the reason on the document.', 'reason');
        }
        if ($type === 'recurring') {
            $d['template_name'] = self::text($in['template_name'] ?? '', 190);
            if ($d['template_name'] === '') {
                $fail('Give this recurring invoice a name, e.g. "Monthly retainer".', 'template_name');
                $d['template_name'] = 'Recurring invoice';
            }
            $freq = (string)($in['frequency'] ?? 'monthly');
            $d['frequency'] = in_array($freq, self::FREQUENCIES, true) ? $freq : 'monthly';
            $d['interval_count'] = max(1, min(24, self::intOf($in['interval_count'] ?? 1) ?: 1));
            $terms = $in['payment_terms_days'] ?? null;
            $d['payment_terms_days'] = is_numeric($terms) ? max(0, min(365, (int)$terms)) : $defaults['payment_terms'];
            $d['auto_send'] = !empty($in['auto_send']) ? 1 : 0;
            $d['anchor_day'] = (int)date('j', strtotime($d['next_run_date']));
        }
        return $d;
    }

    /** Payment schedule rows → cents, summing exactly to the total. */
    private static function milestones(array $in, int $totalC, string $symbol, bool $strict): ?array
    {
        if (count($in) > self::MAX_MILESTONES) {
            if ($strict) throw new QiInvalid('A payment schedule can have at most ' . self::MAX_MILESTONES . ' payments.', 'milestones');
            $in = array_slice($in, 0, self::MAX_MILESTONES);
        }
        $list = [];
        $lastPercent = null;
        foreach ($in as $j => $ms) {
            if (!is_array($ms)) continue;
            $label = self::text($ms['label'] ?? '', 190);
            if ($label === '') $label = 'Payment ' . (count($list) + 1);
            $mode = ($ms['mode'] ?? '') === 'amount' ? 'amount' : 'percent';
            if ($mode === 'percent') {
                $pct = self::numOf($ms['percentage'] ?? 0, 4) ?? 0.0;
                if ($pct <= 0 || $pct > 100) {
                    if ($strict) throw new QiInvalid('Payment ' . ($j + 1) . ': enter a percentage between 0 and 100.', "milestones.$j.percentage");
                    $pct = max(0.0, min(100.0, $pct));
                }
                $amountC = (int)round($totalC * $pct / 100);
                $lastPercent = count($list);
            } else {
                $amountC = (int)round((self::numOf($ms['amount'] ?? 0, 2) ?? 0) * 100);
                if ($amountC <= 0) {
                    if ($strict) throw new QiInvalid('Payment ' . ($j + 1) . ': enter an amount.', "milestones.$j.amount");
                    $amountC = 0;
                }
            }
            $list[] = ['label' => $label, 'mode' => $mode, 'amount_c' => $amountC, 'due_date' => self::dateOf($ms['due_date'] ?? '')];
        }
        if (!$list) return null;
        $diff = $totalC - array_sum(array_column($list, 'amount_c'));
        if ($diff !== 0 && $lastPercent !== null && abs($diff) <= count($list)) {
            $list[$lastPercent]['amount_c'] += $diff; // percentage rounding
        } elseif ($diff !== 0 && $strict) {
            throw new QiInvalid('The payment schedule adds up to ' . QiMoney::format(array_sum(array_column($list, 'amount_c')) / 100, $symbol)
                . ', but the total is ' . QiMoney::format($totalC / 100, $symbol) . '.', 'milestones');
        }
        foreach ($list as &$ms) {
            $ms['percentage'] = $totalC > 0 ? round($ms['amount_c'] / $totalC * 100, 2) : 0.0;
        }
        unset($ms);
        return $list;
    }

    // ------------------------------------------------------------ save

    /**
     * Create (no id) or update a document.
     * @return array{id:int, number:string, created:bool, version:string, lock:array, reposted:bool, replayed?:bool}
     */
    public static function save(PDO $db, int $companyId, int $userId, string $type, array $in): array
    {
        if (!in_array($type, self::TYPES, true)) throw new QiInvalid('Unknown document type');
        $id = self::intOf($in['id'] ?? 0);

        // DDL commits implicitly — provision the headings table before the transaction
        if (in_array($type, ['quote', 'invoice'], true)) {
            try { LineHeadings::ensureTable($db); } catch (Throwable $e) { error_log('[qi] headings table: ' . $e->getMessage()); }
        }

        // A double-click (or a retried request) on "create" returns the first document
        $idem = $id === 0 ? self::claimKey($userId, (string)($in['client_key'] ?? '')) : null;
        if ($idem && $idem['done']) {
            self::releaseKey($idem);
            return ['id' => (int)$idem['done']['id'], 'number' => (string)$idem['done']['number'], 'created' => false, 'replayed' => true,
                    'version' => '', 'lock' => ['level' => 'none', 'reason' => '', 'repost' => false], 'reposted' => false];
        }

        $result = null;
        try {
            $db->beginTransaction();
            $existing = null;
            if ($id > 0) {
                $existing = self::lockRow($db, $companyId, $type, $id);
                if (!$existing) throw new QiInvalid(ucfirst(self::NOUN[$type]) . ' not found.', '', 404);
                $version = (string)($in['version'] ?? '');
                if ($version !== '' && empty($in['force']) && $version !== (string)$existing['updated_at']) {
                    throw new QiInvalid('Someone else saved this ' . self::NOUN[$type] . ' while you were editing it.', 'version', 409, ['conflict' => true]);
                }
            }
            $lock = $existing ? self::lockState($db, $companyId, $type, $existing) : ['level' => 'none', 'reason' => '', 'repost' => false];
            if ($lock['level'] === 'full') throw new QiInvalid($lock['reason'], '', 409, ['locked' => true]);

            if ($lock['level'] === 'wording') {
                $result = self::saveWording($db, $companyId, $userId, $type, $existing, $in);
            } else {
                $d = self::normalize($db, $companyId, $type, $in, $existing, true);
                $result = match ($type) {
                    'quote'       => self::writeQuote($db, $companyId, $userId, $d, $existing),
                    'invoice'     => self::writeInvoice($db, $companyId, $userId, $d, $existing, $lock),
                    'credit_note' => self::writeCreditNote($db, $companyId, $userId, $d, $existing),
                    'recurring'   => self::writeRecurring($db, $companyId, $userId, $d, $existing),
                };
            }
            $table = self::table($type);
            $st = $db->prepare("SELECT updated_at FROM `$table` WHERE id = ? AND company_id = ?");
            $st->execute([$result['id'], $companyId]);
            $result['version'] = (string)$st->fetchColumn();
            $result['lock'] = self::lockState($db, $companyId, $type, self::lockRow($db, $companyId, $type, $result['id']) ?? []);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($idem) self::releaseKey($idem);
            throw $e;
        }
        if ($idem) self::releaseKey($idem, ['id' => $result['id'], 'number' => $result['number']]);

        self::afterSave($db, $companyId, $userId, $type, $result['id']);
        return $result;
    }

    private static function writeQuote(PDO $db, int $companyId, int $userId, array $d, ?array $existing): array
    {
        $t = $d['totals_c'];
        $vals = [
            $d['customer_id'], $d['contact_id'], $d['project_id'], $d['issue_date'], $d['expiry_date'],
            $t['net'] / 100, $t['discount'] / 100, $t['vat'] / 100, $t['total'] / 100,
            $d['currency'], $d['exchange_rate'], $d['terms'], $d['notes'], $d['reference'] ?: null, $d['customer_notes'] ?: null,
            $d['milestones'] ? 1 : 0,
        ];
        if ($existing === null) {
            [$number] = (new SequenceAllocator($db))->allocate($companyId, 'quote', $d['issue_date'], true);
            $db->prepare("INSERT INTO quotes (customer_id, contact_id, project_id, issue_date, expiry_date, subtotal, discount, tax, total,
                                              currency, exchange_rate, terms, notes, reference, customer_notes, has_milestones,
                                              company_id, quote_number, public_token, status, created_by, created_at, updated_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, NOW(), NOW())")
               ->execute(array_merge($vals, [$companyId, $number, bin2hex(random_bytes(16)), $userId]));
            $id = (int)$db->lastInsertId();
            QiActivity::log($db, $companyId, 'quote', $id, 'created', $number, $userId);
            $created = true;
        } else {
            $id = (int)$existing['id'];
            $number = (string)$existing['quote_number'];
            // A declined or expired quote that is revised goes back to draft, to be sent again
            $status = in_array($existing['status'], ['declined', 'expired'], true) ? 'draft' : $existing['status'];
            $db->prepare("UPDATE quotes SET customer_id = ?, contact_id = ?, project_id = ?, issue_date = ?, expiry_date = ?, subtotal = ?, discount = ?,
                                            tax = ?, total = ?, currency = ?, exchange_rate = ?, terms = ?, notes = ?, reference = ?, customer_notes = ?,
                                            has_milestones = ?, status = ?, updated_at = NOW()
                           WHERE id = ? AND company_id = ?")
               ->execute(array_merge($vals, [$status, $id, $companyId]));
            $db->prepare("DELETE FROM quote_lines WHERE quote_id = ?")->execute([$id]);
            QiActivity::log($db, $companyId, 'quote', $id, 'edited', $status !== $existing['status'] ? 'Back to draft' : null, $userId);
            $created = false;
        }
        $ins = $db->prepare("INSERT INTO quote_lines (quote_id, item_description, quantity, unit, unit_price, discount, tax_rate, tax_code_id,
                                                      gl_account_id, project_item_id, line_total, sort_order)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($d['rows'] as $r) {
            if ($r['kind'] !== 'item') continue;
            $ins->execute([$id, $r['description'], $r['qty'], $r['unit'], $r['price'], $r['discount'], $r['rate'], $r['tax_code_id'],
                           $r['gl_account_id'], $r['project_item_id'], $r['gross_c'] / 100, $r['sort']]);
        }
        self::writeHeadings($db, $companyId, LineHeadings::TYPE_QUOTE, $id, $d['rows']);
        self::writeMilestones($db, $companyId, 'quote', $id, $d['milestones']);
        return ['id' => $id, 'number' => $number, 'created' => $created, 'reposted' => false];
    }

    private static function writeInvoice(PDO $db, int $companyId, int $userId, array $d, ?array $existing, array $lock): array
    {
        $t = $d['totals_c'];
        $vals = [
            $d['customer_id'], $d['contact_id'], $d['project_id'], $d['issue_date'], $d['due_date'],
            $t['net'] / 100, $t['discount'] / 100, $t['vat'] / 100, $t['total'] / 100, $t['total'] / 100,
            $d['currency'], $d['exchange_rate'], $d['terms'], $d['notes'], $d['reference'] ?: null, $d['customer_notes'] ?: null,
            $d['milestones'] ? 1 : 0,
        ];
        if ($existing === null) {
            [$number] = (new SequenceAllocator($db))->allocate($companyId, 'invoice', $d['issue_date'], true);
            $db->prepare("INSERT INTO invoices (customer_id, contact_id, project_id, issue_date, due_date, subtotal, discount, tax, total, balance_due,
                                                currency, exchange_rate, terms, notes, reference, customer_notes, has_milestones,
                                                company_id, invoice_number, public_token, status, created_by, created_at, updated_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, NOW(), NOW())")
               ->execute(array_merge($vals, [$companyId, $number, bin2hex(random_bytes(16)), $userId]));
            $id = (int)$db->lastInsertId();
            QiActivity::log($db, $companyId, 'invoice', $id, 'created', $number, $userId);
            $created = true;
        } else {
            $id = (int)$existing['id'];
            $number = (string)$existing['invoice_number'];
            // nothing is paid or credited (lockState) — the balance is the new total
            $status = $existing['status'] === 'overdue' && $d['due_date'] >= date('Y-m-d') ? 'sent' : $existing['status'];
            $db->prepare("UPDATE invoices SET customer_id = ?, contact_id = ?, project_id = ?, issue_date = ?, due_date = ?, subtotal = ?, discount = ?,
                                              tax = ?, total = ?, balance_due = ?, currency = ?, exchange_rate = ?, terms = ?, notes = ?, reference = ?,
                                              customer_notes = ?, has_milestones = ?, status = ?, updated_at = NOW()
                           WHERE id = ? AND company_id = ?")
               ->execute(array_merge($vals, [$status, $id, $companyId]));
            $db->prepare("DELETE FROM invoice_lines WHERE invoice_id = ?")->execute([$id]);
            $created = false;
        }
        $ins = $db->prepare("INSERT INTO invoice_lines (invoice_id, item_description, quantity, unit, unit_price, discount, tax_rate, tax_code_id,
                                                        gl_account_id, inventory_item_id, project_item_id, project_board_id, line_total, sort_order)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($d['rows'] as $r) {
            if ($r['kind'] !== 'item') continue;
            $ins->execute([$id, $r['description'], $r['qty'], $r['unit'], $r['price'], $r['discount'], $r['rate'], $r['tax_code_id'],
                           $r['gl_account_id'], $r['inventory_item_id'], $r['project_item_id'], $r['project_board_id'], $r['gross_c'] / 100, $r['sort']]);
        }
        self::writeHeadings($db, $companyId, LineHeadings::TYPE_INVOICE, $id, $d['rows']);
        self::writeMilestones($db, $companyId, 'invoice', $id, $d['milestones']);

        $reposted = false;
        if (!$created && $lock['repost']) {
            self::repostInvoice($db, $companyId, $userId, $id, $d['project_id']);
            $reposted = true;
        }
        if (!$created) QiActivity::log($db, $companyId, 'invoice', $id, $reposted ? 'reposted' : 'edited', null, $userId);
        return ['id' => $id, 'number' => $number, 'created' => $created, 'reposted' => $reposted];
    }

    /** An issued invoice changed: re-check it as a tax invoice, then replace its ledger entry. */
    private static function repostInvoice(PDO $db, int $companyId, int $userId, int $id, ?int $projectId): void
    {
        require_once __DIR__ . '/../../finances/lib/PostingService.php';
        require_once __DIR__ . '/../../finances/lib/TaxInvoiceValidator.php';
        require_once __DIR__ . '/../../finances/lib/Audit.php';
        if (self::defaults($db, $companyId)['vat_registered']) {
            $errors = array_values(array_filter((new TaxInvoiceValidator($db, $companyId))->validate($id), fn($i) => ($i['severity'] ?? '') === 'error'));
            if ($errors) {
                throw new QiInvalid('This invoice was already issued, and the change would make it an invalid tax invoice: '
                    . implode('; ', array_map(fn($i) => rtrim((string)$i['message'], '.'), $errors)) . '.', '', 422, ['issues' => $errors]);
            }
        }
        (new PostingService($db, $companyId, $userId))->postInvoice($id, $projectId);
        Audit::log('invoice_edited_reposted', [], 'invoice', $id, $companyId, $userId);
    }

    private static function writeCreditNote(PDO $db, int $companyId, int $userId, array $d, ?array $existing): array
    {
        $t = $d['totals_c'];
        if ($d['invoice_id']) {
            // s21: never credit more than is still creditable on the invoice
            $st = $db->prepare("SELECT total, invoice_number FROM invoices WHERE id = ? AND company_id = ? FOR UPDATE");
            $st->execute([$d['invoice_id'], $companyId]);
            $inv = $st->fetch(PDO::FETCH_ASSOC);
            $st = $db->prepare("SELECT COALESCE(SUM(total), 0) FROM credit_notes
                                 WHERE invoice_id = ? AND company_id = ? AND status <> 'cancelled' AND id <> ?");
            $st->execute([$d['invoice_id'], $companyId, $existing ? (int)$existing['id'] : 0]);
            $creditableC = (int)round(((float)$inv['total'] - (float)$st->fetchColumn()) * 100);
            if ($t['total'] > $creditableC) {
                throw new QiInvalid('This credit note is for ' . QiMoney::format($t['total'] / 100, Currencies::symbol($d['currency']))
                    . ', but only ' . QiMoney::format(max(0, $creditableC) / 100, Currencies::symbol($d['currency'])) . ' of invoice '
                    . $inv['invoice_number'] . ' can still be credited.', 'rows');
            }
        }
        $vals = [$d['invoice_id'], $d['customer_id'], $d['issue_date'], $t['net'] / 100, $t['vat'] / 100, $t['total'] / 100,
                 $d['currency'], $d['exchange_rate'], $d['reason'], $d['reason_code'], $d['reference'] ?: null];
        if ($existing === null) {
            [$number] = (new SequenceAllocator($db))->allocate($companyId, 'credit_note', $d['issue_date'], true);
            $db->prepare("INSERT INTO credit_notes (invoice_id, customer_id, issue_date, subtotal, tax, total, currency, exchange_rate, reason, reason_code,
                                                    reference, company_id, credit_note_number, status, created_by, created_at, updated_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, NOW(), NOW())")
               ->execute(array_merge($vals, [$companyId, $number, $userId]));
            $id = (int)$db->lastInsertId();
            QiActivity::log($db, $companyId, 'credit_note', $id, 'created', $number, $userId);
            $created = true;
        } else {
            $id = (int)$existing['id'];
            $number = (string)$existing['credit_note_number'];
            $db->prepare("UPDATE credit_notes SET invoice_id = ?, customer_id = ?, issue_date = ?, subtotal = ?, tax = ?, total = ?, currency = ?,
                                                  exchange_rate = ?, reason = ?, reason_code = ?, reference = ?, updated_at = NOW()
                           WHERE id = ? AND company_id = ?")
               ->execute(array_merge($vals, [$id, $companyId]));
            $db->prepare("DELETE FROM credit_note_lines WHERE credit_note_id = ?")->execute([$id]);
            QiActivity::log($db, $companyId, 'credit_note', $id, 'edited', null, $userId);
            $created = false;
        }
        $ins = $db->prepare("INSERT INTO credit_note_lines (credit_note_id, item_description, quantity, unit, unit_price, discount, tax_rate, tax_code_id,
                                                            gl_account_id, inventory_item_id, line_total, sort_order)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($d['rows'] as $r) {
            if ($r['kind'] !== 'item') continue;
            $ins->execute([$id, $r['description'], $r['qty'], $r['unit'], $r['price'], $r['discount'], $r['rate'], $r['tax_code_id'],
                           $r['gl_account_id'], $r['inventory_item_id'], $r['gross_c'] / 100, $r['sort']]);
        }
        return ['id' => $id, 'number' => $number, 'created' => $created, 'reposted' => false];
    }

    private static function writeRecurring(PDO $db, int $companyId, int $userId, array $d, ?array $existing): array
    {
        $headings = [];
        foreach ($d['rows'] as $r) {
            if ($r['kind'] === 'heading') $headings[] = ['title' => $r['title'], 'sort_order' => $r['sort']];
        }
        $template = json_encode([
            'v' => 1, 'contact_id' => $d['contact_id'], 'project_id' => $d['project_id'], 'reference' => $d['reference'],
            'customer_notes' => $d['customer_notes'], 'headings' => $headings,
        ], JSON_UNESCAPED_UNICODE);
        $vals = [$d['customer_id'], $d['frequency'], $d['interval_count'], $d['next_run_date'], $d['end_date'], $template, $d['template_name'],
                 $d['terms'], $d['notes'], $d['currency'], $d['anchor_day'], $d['auto_send'], $d['payment_terms_days']];
        if ($existing === null) {
            $db->prepare("INSERT INTO recurring_invoices (customer_id, frequency, interval_count, next_run_date, end_date, template_data, template_name,
                                                          terms, notes, currency, anchor_day, auto_send, payment_terms_days,
                                                          company_id, active, created_by, created_at, updated_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())")
               ->execute(array_merge($vals, [$companyId, $userId]));
            $id = (int)$db->lastInsertId();
            QiActivity::log($db, $companyId, 'recurring', $id, 'created', $d['template_name'], $userId);
            $created = true;
        } else {
            $id = (int)$existing['id'];
            $db->prepare("UPDATE recurring_invoices SET customer_id = ?, frequency = ?, interval_count = ?, next_run_date = ?, end_date = ?, template_data = ?,
                                                        template_name = ?, terms = ?, notes = ?, currency = ?, anchor_day = ?, auto_send = ?,
                                                        payment_terms_days = ?, updated_at = NOW()
                           WHERE id = ? AND company_id = ?")
               ->execute(array_merge($vals, [$id, $companyId]));
            $db->prepare("DELETE FROM recurring_invoice_lines WHERE recurring_invoice_id = ?")->execute([$id]);
            QiActivity::log($db, $companyId, 'recurring', $id, 'edited', null, $userId);
            $created = false;
        }
        $ins = $db->prepare("INSERT INTO recurring_invoice_lines (recurring_invoice_id, item_description, quantity, unit, unit_price, discount, tax_rate, tax_code_id, sort_order)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($d['rows'] as $r) {
            if ($r['kind'] !== 'item') continue;
            $ins->execute([$id, $r['description'], $r['qty'], $r['unit'], $r['price'], $r['discount'], $r['rate'], $r['tax_code_id'], $r['sort']]);
        }
        return ['id' => $id, 'number' => $d['template_name'], 'created' => $created, 'reposted' => false];
    }

    /** Paid / credited invoices: only the words around the numbers may change. */
    private static function saveWording(PDO $db, int $companyId, int $userId, string $type, array $existing, array $in): array
    {
        if ($type !== 'invoice') throw new QiInvalid('This ' . self::NOUN[$type] . ' can no longer be changed.', '', 409, ['locked' => true]);
        $due = $existing['due_date'];
        if ($existing['status'] !== 'paid') {
            $want = self::dateOf($in['due_date'] ?? '');
            if ($want !== null) {
                if ($want < $existing['issue_date']) throw new QiInvalid('The due date cannot be before the invoice date.', 'due_date');
                $due = $want;
            }
        }
        $db->prepare("UPDATE invoices SET reference = ?, customer_notes = ?, terms = ?, notes = ?, due_date = ?, updated_at = NOW()
                       WHERE id = ? AND company_id = ?")
           ->execute([
               self::text($in['reference'] ?? '', 120) ?: null,
               self::text($in['customer_notes'] ?? '', 5000, true) ?: null,
               self::text($in['terms'] ?? '', 10000, true),
               self::text($in['notes'] ?? '', 5000, true),
               $due, (int)$existing['id'], $companyId,
           ]);
        QiActivity::log($db, $companyId, 'invoice', (int)$existing['id'], 'edited', 'Wording and dates', $userId);
        return ['id' => (int)$existing['id'], 'number' => (string)$existing['invoice_number'], 'created' => false, 'reposted' => false];
    }

    private static function writeHeadings(PDO $db, int $companyId, string $parentType, int $id, array $rows): void
    {
        $headings = [];
        foreach ($rows as $r) {
            if ($r['kind'] === 'heading') $headings[] = ['title' => $r['title'], 'sort_order' => $r['sort']];
        }
        try {
            LineHeadings::replace($db, $companyId, $parentType, $id, $headings);
        } catch (Throwable $e) {
            if ($headings) throw $e;
            // no headings to keep and the table is missing — nothing to do
        }
    }

    private static function writeMilestones(PDO $db, int $companyId, string $entity, int $id, ?array $milestones): void
    {
        $db->prepare("DELETE FROM payment_milestones WHERE entity_type = ? AND entity_id = ? AND company_id = ?")->execute([$entity, $id, $companyId]);
        if (!$milestones) return;
        $ins = $db->prepare("INSERT INTO payment_milestones (entity_type, entity_id, company_id, label, percentage, amount, due_date, status, sort_order)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($milestones as $i => $ms) {
            $ins->execute([$entity, $id, $companyId, $ms['label'], $ms['percentage'], $ms['amount_c'] / 100, $ms['due_date'], $i === 0 ? 'due' : 'pending', $i]);
        }
    }

    /** PDF + Drive copy and calendar dates — after commit, best-effort. */
    private static function afterSave(PDO $db, int $companyId, int $userId, string $type, int $id): void
    {
        if ($type === 'recurring') return;
        try {
            require_once __DIR__ . '/../services/DocumentPdfService.php';
            DocumentPdfService::generateAndFile($db, $companyId, $type, $id, $userId);
        } catch (Throwable $e) {
            error_log('[qi] pdf after save: ' . $e->getMessage());
        }
        if ($type === 'credit_note') return;
        try {
            require_once __DIR__ . '/../services/CalendarHook.php';
            $hook = new CalendarHook($db);
            if ($type === 'quote') {
                $st = $db->prepare("SELECT quote_number, expiry_date FROM quotes WHERE id = ? AND company_id = ?");
                $st->execute([$id, $companyId]);
                if ($r = $st->fetch(PDO::FETCH_ASSOC)) $hook->handleQuoteEvent($companyId, $id, $r['quote_number'], $r['expiry_date'], $userId);
            } else {
                $st = $db->prepare("SELECT invoice_number, due_date FROM invoices WHERE id = ? AND company_id = ?");
                $st->execute([$id, $companyId]);
                if ($r = $st->fetch(PDO::FETCH_ASSOC)) $hook->handleInvoiceEvent($companyId, $id, $r['invoice_number'], $r['due_date'], $userId);
            }
        } catch (Throwable $e) {
            error_log('[qi] calendar after save: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------ helpers

    public static function table(string $type): string
    {
        return ['quote' => 'quotes', 'invoice' => 'invoices', 'credit_note' => 'credit_notes', 'recurring' => 'recurring_invoices'][$type];
    }

    public static function lockRow(PDO $db, int $companyId, string $type, int $id): ?array
    {
        $table = self::table($type);
        $st = $db->prepare("SELECT * FROM `$table` WHERE id = ? AND company_id = ?" . ($db->inTransaction() ? ' FOR UPDATE' : ''));
        $st->execute([$id, $companyId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Ledger/stock/project links of a document's stored lines, by line id. */
    private static function lineExtras(PDO $db, string $type, int $id): array
    {
        $map = [
            'quote'       => ['quote_lines', 'quote_id', 'gl_account_id, NULL AS inventory_item_id, project_item_id, NULL AS project_board_id'],
            'invoice'     => ['invoice_lines', 'invoice_id', 'gl_account_id, inventory_item_id, project_item_id, project_board_id'],
            'credit_note' => ['credit_note_lines', 'credit_note_id', 'gl_account_id, inventory_item_id, NULL AS project_item_id, NULL AS project_board_id'],
        ];
        if (!isset($map[$type])) return [];
        [$table, $fk, $cols] = $map[$type];
        try {
            $st = $db->prepare("SELECT id, $cols FROM `$table` WHERE `$fk` = ?");
            $st->execute([$id]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['id']] = [
                    'gl_account_id'     => $r['gl_account_id'] !== null ? (int)$r['gl_account_id'] : null,
                    'inventory_item_id' => $r['inventory_item_id'] !== null ? (int)$r['inventory_item_id'] : null,
                    'project_item_id'   => $r['project_item_id'] !== null ? (int)$r['project_item_id'] : null,
                    'project_board_id'  => $r['project_board_id'] !== null ? (int)$r['project_board_id'] : null,
                ];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Plain text: no control characters (newlines only where allowed), trimmed, capped. */
    public static function text($v, int $max, bool $multiline = false): string
    {
        if (!is_scalar($v)) return '';
        $s = (string)$v;
        if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = $multiline ? preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) : preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
        $s = trim((string)$s);
        if ($multiline) $s = preg_replace("/\n{4,}/", "\n\n\n", $s) ?? $s;
        return mb_substr($s, 0, $max);
    }

    public static function dateOf($v): ?string
    {
        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($v), $m)) return null;
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[1] < 2000 || (int)$m[1] > 2100) return null;
        return trim($v);
    }

    /** A number from the editor ("1 234,50" and "1,234.50" both work); null when it is not one. */
    public static function numOf($v, int $decimals): ?float
    {
        if (is_int($v) || is_float($v)) {
            if (!is_finite((float)$v)) return null;
            return round((float)$v, $decimals);
        }
        if (!is_string($v)) return null;
        $s = str_replace([' ', "\u{00A0}", "\u{202F}", 'R', '%'], '', trim($v));
        if ($s === '' ) return 0.0;
        if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $s)) $s = str_replace(',', '', $s);
        elseif (preg_match('/^-?\d+,\d+$/', $s)) $s = str_replace(',', '.', $s);
        if (!is_numeric($s)) return null;
        return round((float)$s, $decimals);
    }

    private static function intOf($v): int
    {
        return is_numeric($v) ? max(0, (int)$v) : 0;
    }

    // ------------------------------------------------------------ create once

    private static function claimKey(int $userId, string $key): ?array
    {
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $key)) return null;
        $dir = dirname(__DIR__, 2) . '/storage/qi/idem';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return null;
        if (random_int(1, 50) === 1) {
            foreach (glob($dir . '/*.json') ?: [] as $f) {
                if (@filemtime($f) < time() - 86400) @unlink($f);
            }
        }
        $fh = @fopen($dir . '/' . $userId . '_' . $key . '.json', 'c+');
        if (!$fh) return null;
        if (!flock($fh, LOCK_EX)) { fclose($fh); return null; }
        $done = json_decode((string)stream_get_contents($fh), true);
        return ['fh' => $fh, 'done' => is_array($done) && !empty($done['id']) ? $done : null];
    }

    private static function releaseKey(array $idem, ?array $done = null): void
    {
        if ($done !== null) {
            ftruncate($idem['fh'], 0);
            rewind($idem['fh']);
            fwrite($idem['fh'], json_encode($done));
            fflush($idem['fh']);
        }
        flock($idem['fh'], LOCK_UN);
        fclose($idem['fh']);
    }
}
