<?php
// /receipts/lib/RcBills.php — a receipt becomes a supplier bill.
//
// The bill is made, changed and posted by Finance's own engine
// (FinPayables::saveBill / postBill / pay), so a bill from Receipts is
// exactly the bill Finance would have made: each line's net to the cent, VAT
// per line, duplicate numbers refused, control accounts refused, the period
// lock respected and every write in one transaction. Receipts adds:
//   - amounts as printed on the slip (VAT included) turned into exact net
//     lines (a cent or two of discount keeps the net exact);
//   - the checks (supplier compliance, VAT number, purchase order, duplicates,
//     the slip's total, price jumps, closed periods) with one-click fixes, and
//     admin overrides with a reason that stays in receipt_policy_log;
//   - "paid on the spot": the supplier payment is recorded with the posting;
//   - the slip attached to the bill in Finance (SARS wants the source document);
//   - project costs written to the board's cost column (or the item's activity).
//
// The form as typed (VAT-inclusive prices, the slip's total) is kept in
// receipt_file.form_json and used again while the bill still matches it; a
// bill changed in Finance since is shown as Finance has it.

require_once dirname(__DIR__, 2) . '/finances/lib/FinPayables.php';
require_once dirname(__DIR__, 2) . '/finances/lib/FinFiles.php';
require_once dirname(__DIR__, 2) . '/finances/lib/PeriodService.php';
require_once dirname(__DIR__, 2) . '/projects/lib/autoload.php';

final class RcBlocked extends RuntimeException
{
    public array $checks;
    public function __construct(string $message, array $checks)
    {
        parent::__construct($message, 409);
        $this->checks = $checks;
    }
}

final class RcBills
{
    /** Checks an admin may post past, with a reason. */
    public const OVERRIDABLE = ['compliance', 'po', 'vat_number'];

    // ================================================================ reading

    /** One receipt with its reading and its bill's state (null when not this company's). */
    public static function row(PDO $db, int $companyId, int $fileId, bool $lock = false): ?array
    {
        $st = $db->prepare("SELECT rf.*, ro.ocr_id, ro.vendor_name, ro.vendor_vat, ro.invoice_number, ro.invoice_date, ro.currency, ro.subtotal, ro.tax, ro.total,
                                   ro.line_items_json, ro.confidence_score, ro.raw_json,
                                   b.id AS b_id, b.status AS b_status, b.total AS b_total, b.journal_id AS b_journal, b.supplier_id AS b_supplier,
                                   b.vendor_invoice_number AS b_number, b.issue_date AS b_issued, " . RcKit::statusSql() . " AS state
                              FROM receipt_file rf
                              LEFT JOIN receipt_ocr ro ON ro.file_id = rf.file_id
                              LEFT JOIN ap_bills b ON b.id = rf.bill_id AND b.company_id = rf.company_id
                             WHERE rf.file_id = ? AND rf.company_id = ?" . ($lock ? ' FOR UPDATE' : ''));
        $st->execute([$fileId, $companyId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function canSee(array $row, string $role, int $userId): bool
    {
        return RcKit::canSeeAll($role) || (int)$row['uploaded_by'] === $userId;
    }

    /** A receipt the person may open, or a 404. */
    public static function need(PDO $db, int $companyId, int $userId, string $role, int $fileId, bool $lock = false): array
    {
        $row = $fileId > 0 ? self::row($db, $companyId, $fileId, $lock) : null;
        if (!$row || !self::canSee($row, $role, $userId)) throw new RcProblem('That receipt was not found.', 404);
        return $row;
    }

    /** Everything the review page needs for one receipt. */
    public static function load(PDO $db, int $companyId, int $userId, string $role, int $fileId): array
    {
        $r = self::need($db, $companyId, $userId, $role, $fileId);
        $settings = RcKit::settings($db, $companyId);
        $raw = json_decode((string)($r['raw_json'] ?? ''), true) ?: [];
        $reading = [
            'state' => $r['ocr_status'], 'engine' => (string)($r['read_engine'] ?? ($raw['engine'] ?? '')), 'error' => (string)($r['read_error'] ?? ''),
            'at' => $r['read_at'], 'confidence' => (float)($r['confidence_score'] ?? 0),
            'vendor' => (string)($r['vendor_name'] ?? ''), 'vat' => (string)($r['vendor_vat'] ?? ''), 'number' => (string)($r['invoice_number'] ?? ''),
            'date' => $r['invoice_date'], 'due' => $raw['fields']['due_date'] ?? null, 'currency' => (string)($r['currency'] ?? 'ZAR'),
            'subtotal' => $r['subtotal'] !== null ? (float)$r['subtotal'] : null, 'tax' => $r['tax'] !== null ? (float)$r['tax'] : null,
            'total' => $r['total'] !== null ? (float)$r['total'] : null,
        ];
        $form = $r['b_id'] ? self::formFromBill($db, $companyId, $r) : self::prefill($db, $companyId, $userId, $role, $r, $raw, $settings);
        $supplier = $form['supplier_id'] ? self::supplierInfo($db, $companyId, (int)$form['supplier_id']) : null;
        return [
            'receipt' => self::shapeRow($db, $r),
            'reading' => $reading,
            'form' => $form,
            'supplier' => $supplier,
            'suggestion' => $form['suggestion'] ?? null,
            'queue' => self::neighbours($db, $companyId, $userId, $role, $r),
            'editable' => RcKit::canCapture($role) && in_array($r['state'], ['review', 'draft', 'reading'], true),
        ];
    }

    /** A receipt row for lists and the page head. */
    public static function shapeRow(PDO $db, array $r): array
    {
        $id = (int)$r['file_id'];
        return [
            'id' => $id, 'state' => (string)$r['state'], 'state_label' => RcKit::STATUS[$r['state']] ?? $r['state'],
            'name' => (string)($r['original_name'] ?: basename((string)$r['path'])), 'mime' => (string)$r['mime'], 'size' => (int)$r['size_bytes'],
            'pdf' => $r['mime'] === 'application/pdf', 'url' => '/receipts/api/file.php?id=' . $id, 'thumb' => !empty($r['thumb_path']) || str_starts_with((string)$r['mime'], 'image/') ? '/receipts/api/file.php?id=' . $id . '&thumb=1' : null,
            'uploaded_at' => $r['uploaded_at'], 'uploaded_by' => (int)$r['uploaded_by'], 'uploader' => RcKit::personName($db, (int)$r['uploaded_by']),
            'note' => (string)($r['note'] ?? ''), 'bill_id' => $r['b_id'] ? (int)$r['b_id'] : null, 'journal_id' => $r['b_journal'] ? (int)$r['b_journal'] : null,
            'bill_url' => $r['b_id'] ? '/finances/#payables?bill=' . (int)$r['b_id'] : null,
            'aside' => $r['dismissed_at'] ? ['at' => $r['dismissed_at'], 'by' => RcKit::personName($db, (int)$r['dismissed_by']), 'reason' => (string)$r['dismiss_reason']] : null,
            'legacy_invoice' => !empty($r['invoice_id']) && !$r['b_id'],
        ];
    }

    /** The receipts before and after this one in the review queue (oldest first). */
    private static function neighbours(PDO $db, int $companyId, int $userId, string $role, array $r): array
    {
        $args = [$companyId];
        $scope = RcKit::scopeSql($role, $userId, $args);
        $st = $db->prepare("SELECT rf.file_id FROM receipt_file rf LEFT JOIN ap_bills b ON b.id = rf.bill_id AND b.company_id = rf.company_id
                             WHERE rf.company_id = ? AND $scope AND rf.dismissed_at IS NULL AND (b.id IS NULL OR b.status IN ('draft','review','approved'))
                          ORDER BY rf.uploaded_at, rf.file_id LIMIT 2000");
        $st->execute($args);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $i = array_search((int)$r['file_id'], $ids, true);
        if ($i === false) return ['prev' => null, 'next' => $ids[0] ?? null, 'pos' => 0, 'count' => count($ids)];
        return ['prev' => $ids[$i - 1] ?? null, 'next' => $ids[$i + 1] ?? null, 'pos' => $i + 1, 'count' => count($ids)];
    }

    // ================================================================ prefill

    /** A first draft of the bill from what was read and what was learned. */
    private static function prefill(PDO $db, int $companyId, int $userId, string $role, array $r, array $raw, array $settings): array
    {
        $f = (array)($raw['fields'] ?? []);
        $vendor = trim((string)($r['vendor_name'] ?? ''));
        $sugg = self::suggestSupplier($db, $companyId, $vendor, (string)($r['vendor_vat'] ?? ''));
        $supplierId = $sugg ? (int)$sugg['id'] : null;
        $map = RcLearn::map($db, $companyId);
        $rate = self::standardRate($db, $companyId);
        $total = $r['total'] !== null ? (float)$r['total'] : null;
        $tax = $r['tax'] !== null ? (float)$r['tax'] : null;
        $sub = $r['subtotal'] !== null ? (float)$r['subtotal'] : null;
        // No VAT on the slip and the supplier is not registered: nothing to claim.
        $hasVat = ($tax !== null && $tax > 0.004) || !empty($r['vendor_vat']);
        $lineRate = $hasVat || ($tax === null && $supplierId && self::supplierVat($db, $companyId, $supplierId) !== '') ? $rate : 0.0;
        if ($tax !== null && $tax <= 0.004 && $total) $lineRate = 0.0;
        $aiAccount = self::accountByCode($db, $companyId, (string)($raw['account_code'] ?? ''));
        $default = self::defaultAccount($db, $companyId, $settings);

        $lines = [];
        $items = json_decode((string)($r['line_items_json'] ?? ''), true);
        $incl = $f['prices_include_vat'] ?? null;
        if (is_array($items) && $items && $settings['auto_split_lines']) {
            foreach ($items as $it) {
                if (!is_array($it) || trim((string)($it['description'] ?? '')) === '') continue;
                [$acc, $why] = RcLearn::accountFor($db, $companyId, $map, $supplierId, (string)$it['description']);
                $lines[] = ['description' => mb_substr(trim((string)$it['description']), 0, 255), 'qty' => (float)($it['qty'] ?? 1) ?: 1.0,
                            'price' => round((float)($it['unit_price'] ?? ($it['amount'] ?? 0)), 2), 'rate' => $lineRate,
                            'account_id' => $acc ?: ($aiAccount ?: $default), 'account_source' => $acc ? $why : ($aiAccount ? 'ai' : ($default ? 'default' : '')), 'item_id' => null];
            }
        }
        if (!$lines) {
            $names = array_filter((array)($f['item_names'] ?? []));
            $desc = $names ? implode(', ', array_slice($names, 0, 3)) : ($vendor !== '' ? 'Purchase from ' . $vendor : '');
            [$acc, $why] = RcLearn::accountFor($db, $companyId, $map, $supplierId, $desc);
            $amount = $total !== null ? $total : ($sub ?? 0);
            $incl = $total !== null;
            if ($total === null && $sub !== null) $incl = false;
            $lines[] = ['description' => mb_substr($desc, 0, 255), 'qty' => 1.0, 'price' => round((float)$amount, 2), 'rate' => $lineRate,
                        'account_id' => $acc ?: ($aiAccount ?: $default), 'account_source' => $acc ? $why : ($aiAccount ? 'ai' : ($default ? 'default' : '')), 'item_id' => null];
        }
        if ($incl === null) $incl = true;
        $issued = $r['invoice_date'] ?: substr((string)$r['uploaded_at'], 0, 10);
        $due = $f['due_date'] ?? null;
        if (!$due && $issued) $due = self::dueFor($db, $companyId, $supplierId, $issued, (int)$settings['default_due_days']);
        $board = RcLearn::boardFor($map, $supplierId);
        if ($board && !ProjectLink::canSeeBoard($db, $companyId, $userId, $role, $board)) $board = null;
        return [
            'supplier_id' => $supplierId, 'number' => (string)($r['invoice_number'] ?? ''), 'issued' => $issued, 'due' => $due,
            'vendor_vat' => (string)($r['vendor_vat'] ?? ''), 'notes' => (string)($r['note'] ?? ''), 'board_id' => $board, 'po_id' => null,
            'incl' => (bool)$incl, 'slip_total' => $total, 'currency' => (string)($r['currency'] ?: 'ZAR'),
            'paid' => ['method' => '', 'date' => $issued, 'bank_account_id' => null],
            'lines' => $lines, 'suggestion' => $sugg, 'from' => 'reading',
        ];
    }

    /** The form for a receipt that already has a bill. */
    private static function formFromBill(PDO $db, int $companyId, array $r): array
    {
        $billId = (int)$r['b_id'];
        $st = $db->prepare("SELECT * FROM ap_bills WHERE id = ? AND company_id = ?");
        $st->execute([$billId, $companyId]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
        $st = $db->prepare("SELECT * FROM ap_bill_lines WHERE bill_id = ? ORDER BY sort_order, id");
        $st->execute([$billId]);
        $bl = $st->fetchAll(PDO::FETCH_ASSOC);
        $saved = json_decode((string)($r['form_json'] ?? ''), true);
        $board = null;
        foreach ($bl as $l) if ($l['project_board_id']) { $board = (int)$l['project_board_id']; break; }
        $paid = ['method' => (string)($r['paid_method'] ?? ''), 'date' => $r['paid_date'] ?: $b['issue_date'], 'bank_account_id' => $r['paid_bank_account_id'] ? (int)$r['paid_bank_account_id'] : null];
        $base = [
            'supplier_id' => (int)$b['supplier_id'], 'number' => (string)$b['vendor_invoice_number'], 'issued' => $b['issue_date'], 'due' => $b['due_date'],
            'vendor_vat' => (string)($b['vendor_vat'] ?? ''), 'notes' => (string)($b['notes'] ?? ''), 'board_id' => $board, 'po_id' => $r['po_id'] ? (int)$r['po_id'] : null,
            'currency' => 'ZAR', 'paid' => $paid, 'bill_status' => (string)$b['status'], 'from' => 'bill',
        ];
        if (is_array($saved) && ($saved['hash'] ?? '') === self::billHash($bl)) {
            return $base + ['incl' => !empty($saved['incl']), 'slip_total' => isset($saved['slip_total']) ? (float)$saved['slip_total'] : null, 'lines' => (array)$saved['lines']];
        }
        $lines = array_map(function ($l) {
            $qty = (float)$l['quantity'] ?: 1.0;
            $net = (float)$l['quantity'] * (float)$l['unit_price'] - (float)$l['discount'];
            return ['description' => (string)$l['item_description'], 'qty' => $qty, 'price' => round($net / $qty, 2), 'rate' => (float)$l['tax_rate'],
                    'account_id' => $l['gl_account_id'] ? (int)$l['gl_account_id'] : null, 'account_source' => '', 'item_id' => $l['project_item_id'] ? (int)$l['project_item_id'] : null];
        }, $bl);
        return $base + ['incl' => false, 'slip_total' => $r['total'] !== null ? (float)$r['total'] : null, 'lines' => $lines, 'changed_in_finance' => is_array($saved)];
    }

    private static function billHash(array $billLines): string
    {
        return sha1(json_encode(array_map(fn($l) => [(string)$l['item_description'], round((float)$l['quantity'], 3), round((float)$l['unit_price'], 2), round((float)$l['discount'], 2),
                                                     round((float)$l['tax_rate'], 2), (int)$l['gl_account_id'], (int)$l['project_board_id'], (int)$l['project_item_id']], $billLines)));
    }

    // ============================================================== suppliers

    /** The supplier a printed name (or VAT number) belongs to, with how sure we are. */
    public static function suggestSupplier(PDO $db, int $companyId, string $vendorName, string $vat = ''): ?array
    {
        $vat = preg_replace('/\D/', '', $vat);
        $active = "company_id = ? AND type = 'supplier' AND deleted_at IS NULL";
        if (preg_match('/^4\d{9}$/', $vat)) {
            $st = $db->prepare("SELECT id, name FROM crm_accounts WHERE $active AND REPLACE(vat_no, ' ', '') = ? ORDER BY id LIMIT 1");
            $st->execute([$companyId, $vat]);
            if ($s = $st->fetch(PDO::FETCH_ASSOC)) return ['id' => (int)$s['id'], 'name' => $s['name'], 'confidence' => 0.97, 'source' => 'vat', 'alternatives' => []];
        }
        $vendorName = trim($vendorName);
        if ($vendorName === '') return null;
        if ($id = RcLearn::supplierFor($db, $companyId, $vendorName)) {
            $st = $db->prepare("SELECT id, name FROM crm_accounts WHERE id = ? AND $active");
            $st->execute([$id, $companyId]);
            if ($s = $st->fetch(PDO::FETCH_ASSOC)) return ['id' => (int)$s['id'], 'name' => $s['name'], 'confidence' => 0.95, 'source' => 'learned', 'alternatives' => []];
        }
        $st = $db->prepare("SELECT id, name FROM crm_accounts WHERE $active AND name = ? LIMIT 1");
        $st->execute([$companyId, $vendorName]);
        if ($s = $st->fetch(PDO::FETCH_ASSOC)) return ['id' => (int)$s['id'], 'name' => $s['name'], 'confidence' => 0.9, 'source' => 'name', 'alternatives' => []];
        // Close names: "ENGEN FOURWAYS" ~ "Engen", "Builders Warehouse (Pty) Ltd" ~ "Builders"
        $norm = self::normName($vendorName);
        if ($norm === '') return null;
        $first = explode(' ', $norm)[0];
        $st = $db->prepare("SELECT id, name FROM crm_accounts WHERE $active AND (name LIKE ? OR name LIKE ?) ORDER BY name LIMIT 40");
        $st->execute([$companyId, '%' . RcKit::likeEscape($vendorName) . '%', '%' . RcKit::likeEscape($first) . '%']);
        $best = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $n = self::normName($s['name']);
            if ($n === '') continue;
            similar_text($norm, $n, $pct);
            $contains = str_contains($norm, $n) || str_contains($n, $norm);
            $score = max($pct / 100, $contains ? 0.8 : 0);
            if ($score >= 0.6) $best[] = ['id' => (int)$s['id'], 'name' => $s['name'], 'score' => $score];
        }
        if (!$best) return null;
        usort($best, fn($a, $b) => $b['score'] <=> $a['score']);
        $top = array_shift($best);
        return ['id' => $top['id'], 'name' => $top['name'], 'confidence' => round(min(0.8, $top['score']) * 0.85, 2), 'source' => 'similar',
                'alternatives' => array_map(fn($x) => ['id' => $x['id'], 'name' => $x['name']], array_slice($best, 0, 4))];
    }

    public static function normName(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/\((pty|edms)\)|\b(pty|ltd|limited|cc|inc|bpk|edms|t\/a|trading as|the|sa|south africa|group|holdings)\b|[^\p{L}\p{N} ]+/u', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', (string)$s));
    }

    private static function supplierVat(PDO $db, int $companyId, int $supplierId): string
    {
        $st = $db->prepare("SELECT vat_no FROM crm_accounts WHERE id = ? AND company_id = ?");
        $st->execute([$supplierId, $companyId]);
        return preg_replace('/\s+/', '', (string)$st->fetchColumn());
    }

    /** Required CRM documents the supplier lacks or has let expire. */
    public static function compliance(PDO $db, int $companyId, int $supplierId): array
    {
        try {
            $st = $db->prepare("SELECT id, name FROM crm_compliance_types WHERE company_id = ? AND required = 1 ORDER BY name");
            $st->execute([$companyId]);
            $types = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$types) return ['ok' => true, 'missing' => []];
            // Latest expiry per type; a document without an expiry date never expires.
            $st = $db->prepare("SELECT type_id, MAX(COALESCE(expiry_date, '9999-12-31')) FROM crm_compliance_docs WHERE company_id = ? AND account_id = ? GROUP BY type_id");
            $st->execute([$companyId, $supplierId]);
            $latest = array_map('strval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
        } catch (Throwable $e) {
            return ['ok' => true, 'missing' => []];
        }
        $today = date('Y-m-d');
        $missing = [];
        foreach ($types as $t) {
            $exp = $latest[(int)$t['id']] ?? null;
            if ($exp === null) $missing[] = $t['name'];
            elseif ($exp >= '1000-01-02' && $exp < $today) $missing[] = $t['name'] . ' (expired)'; // zero dates count as no expiry
        }
        return ['ok' => !$missing, 'missing' => $missing];
    }

    /** What the review page shows about a supplier. */
    public static function supplierInfo(PDO $db, int $companyId, int $supplierId): ?array
    {
        $st = $db->prepare("SELECT id, name, vat_no, status, email, phone, deleted_at FROM crm_accounts WHERE id = ? AND company_id = ? AND type = 'supplier'");
        $st->execute([$supplierId, $companyId]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) return null;
        $st = $db->prepare("SELECT COUNT(*), COALESCE(SUM(total), 0), MAX(issue_date) FROM ap_bills WHERE company_id = ? AND supplier_id = ? AND status IN ('posted','paid') AND issue_date >= ?");
        $st->execute([$companyId, $supplierId, date('Y-m-d', strtotime('-12 months'))]);
        [$n, $sum, $last] = $st->fetch(PDO::FETCH_NUM);
        return [
            'id' => (int)$s['id'], 'name' => (string)$s['name'], 'vat_no' => preg_replace('/\s+/', '', (string)$s['vat_no']), 'status' => (string)$s['status'],
            'deleted' => !empty($s['deleted_at']), 'email' => (string)($s['email'] ?? ''), 'phone' => (string)($s['phone'] ?? ''),
            'compliance' => self::compliance($db, $companyId, $supplierId), 'url' => '/crm/account_view.php?id=' . (int)$s['id'],
            'year' => ['bills' => (int)$n, 'total' => round((float)$sum, 2), 'last' => $last], 'pos' => self::openPos($db, $companyId, $supplierId),
        ];
    }

    /** Purchase orders of a supplier that a bill could be for. */
    public static function openPos(PDO $db, int $companyId, int $supplierId, ?int $keep = null): array
    {
        try {
            $st = $db->prepare("SELECT id, po_number, total, status, created_at FROM purchase_orders WHERE company_id = ? AND supplier_id = ?
                                   AND (status IN ('approved','partial','complete') OR id = ?) ORDER BY created_at DESC LIMIT 30");
            $st->execute([$companyId, $supplierId, (int)$keep]);
            return array_map(fn($p) => ['id' => (int)$p['id'], 'number' => (string)$p['po_number'], 'total' => round((float)$p['total'], 2), 'status' => (string)$p['status'],
                                        'date' => substr((string)$p['created_at'], 0, 10)], $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** A new supplier from the review page, through the CRM (its duplicate checks and rules). */
    public static function createSupplier(PDO $db, int $companyId, int $userId, string $role, array $in): array
    {
        if (!RcKit::canCapture($role)) throw new RcForbidden('Only admins and bookkeepers can add suppliers from Receipts.');
        require_once dirname(__DIR__, 2) . '/crm/lib/CrmAccounts.php';
        $vat = preg_replace('/\s+/', '', (string)($in['vat_no'] ?? ''));
        if ($vat !== '' && !preg_match('/^4\d{9}$/', $vat)) throw new RcProblem('A South African VAT number has 10 digits and starts with 4.', 422);
        try {
            $res = CrmAccounts::save($db, $companyId, $userId, $role, ['type' => 'supplier', 'name' => (string)($in['name'] ?? ''), 'vat_no' => $vat,
                'email' => (string)($in['email'] ?? ''), 'phone' => (string)($in['phone'] ?? ''), 'confirm_duplicate' => !empty($in['confirm_duplicate']) ? 1 : 0]);
        } catch (CrmUserError $e) {
            throw new RcProblem($e->getMessage(), 422);
        } catch (CrmForbidden $e) {
            throw new RcForbidden($e->getMessage());
        }
        if (!empty($res['duplicates'])) {
            return ['created' => false, 'duplicates' => array_map(fn($d) => ['id' => (int)($d['id'] ?? 0), 'name' => (string)($d['name'] ?? '')], (array)$res['duplicates'])];
        }
        return ['created' => true, 'supplier' => self::supplierInfo($db, $companyId, (int)$res['id'])];
    }

    /** Put the VAT number printed on the slip on the supplier's CRM record. */
    public static function saveSupplierVat(PDO $db, int $companyId, int $userId, string $role, int $supplierId, string $vat): void
    {
        if (!RcKit::canCapture($role)) throw new RcForbidden('Only admins and bookkeepers can change suppliers from Receipts.');
        $vat = preg_replace('/\D/', '', $vat);
        if (!preg_match('/^4\d{9}$/', $vat)) throw new RcProblem('A South African VAT number has 10 digits and starts with 4.', 422);
        $st = $db->prepare("UPDATE crm_accounts SET vat_no = ?, updated_at = NOW() WHERE id = ? AND company_id = ? AND type = 'supplier'");
        $st->execute([$vat, $supplierId, $companyId]);
        if (!$st->rowCount()) throw new RcProblem('That supplier was not found.', 404);
        RcKit::audit($db, $companyId, $userId, 'receipt_supplier_vat_saved', null, ['supplier_id' => $supplierId, 'vat_no' => $vat]);
    }

    // =============================================================== helpers

    public static function standardRate(PDO $db, int $companyId): float
    {
        try {
            return (new TaxCodes($db, $companyId))->standardRatePercent();
        } catch (Throwable $e) {
            return 15.0;
        }
    }

    private static function accountByCode(PDO $db, int $companyId, string $code): ?int
    {
        if ($code === '') return null;
        $st = $db->prepare("SELECT a.account_id FROM gl_accounts a WHERE a.company_id = ? AND a.account_code = ? AND a.is_active = 1 AND COALESCE(a.is_control, 0) = 0
                               AND NOT EXISTS (SELECT 1 FROM gl_accounts c WHERE c.company_id = a.company_id AND c.parent_id = a.account_id) LIMIT 1");
        $st->execute([$companyId, $code]);
        return (int)$st->fetchColumn() ?: null;
    }

    /** Settings' default expense account, else General Expenses, else the first expense account. */
    public static function defaultAccount(PDO $db, int $companyId, array $settings): ?int
    {
        if ($id = self::accountByCode($db, $companyId, (string)$settings['default_expense_account'])) return $id;
        $st = $db->prepare("SELECT a.account_id FROM gl_accounts a WHERE a.company_id = ? AND a.account_type = 'expense' AND a.is_active = 1 AND COALESCE(a.is_control, 0) = 0
                               AND NOT EXISTS (SELECT 1 FROM gl_accounts c WHERE c.company_id = a.company_id AND c.parent_id = a.account_id)
                          ORDER BY (a.account_name LIKE '%general expense%') DESC, a.account_code LIMIT 1");
        $st->execute([$companyId]);
        return (int)$st->fetchColumn() ?: null;
    }

    /** Due date: the supplier's usual terms (their last bill), else the default days (0 = none). */
    private static function dueFor(PDO $db, int $companyId, ?int $supplierId, string $issued, int $days): ?string
    {
        if ($supplierId) {
            $st = $db->prepare("SELECT DATEDIFF(due_date, issue_date) FROM ap_bills WHERE company_id = ? AND supplier_id = ? AND due_date IS NOT NULL ORDER BY issue_date DESC, id DESC LIMIT 1");
            $st->execute([$companyId, $supplierId]);
            $d = $st->fetchColumn();
            if ($d !== false && (int)$d >= 0 && (int)$d <= 180) $days = (int)$d;
        }
        return $days > 0 ? date('Y-m-d', strtotime($issued . " +$days days")) : ($supplierId ? $issued : null);
    }

    /** Accounts, VAT rates, banks and project boards for the review form. */
    public static function lookups(PDO $db, int $companyId, int $userId, string $role): array
    {
        // Accounts a bill line may go to: active, not a control account, not a heading with sub-accounts under it.
        $st = $db->prepare("SELECT a.account_id, a.account_code, a.account_name, a.account_type, a.account_subtype, p.account_name parent FROM gl_accounts a
                              LEFT JOIN gl_accounts p ON p.account_id = a.parent_id AND p.company_id = a.company_id
                             WHERE a.company_id = ? AND a.is_active = 1 AND COALESCE(a.is_control, 0) = 0 AND a.account_type IN ('expense','asset','liability','equity')
                               AND NOT EXISTS (SELECT 1 FROM gl_accounts c WHERE c.company_id = a.company_id AND c.parent_id = a.account_id)
                          ORDER BY FIELD(a.account_type, 'expense', 'asset', 'liability', 'equity'), a.account_code");
        $st->execute([$companyId]);
        $accounts = array_map(fn($a) => ['id' => (int)$a['account_id'], 'code' => (string)$a['account_code'], 'name' => (string)$a['account_name'],
                                         'type' => (string)$a['account_type'], 'sub' => (string)($a['account_subtype'] ?? ''), 'group' => (string)($a['parent'] ?? '')], $st->fetchAll(PDO::FETCH_ASSOC));
        $rate = self::standardRate($db, $companyId);
        $banks = [];
        try {
            $st = $db->prepare("SELECT id, name, bank_name, account_no FROM gl_bank_accounts WHERE company_id = ? AND is_active = 1 ORDER BY name");
            $st->execute([$companyId]);
            $banks = array_map(fn($b) => ['id' => (int)$b['id'], 'name' => (string)$b['name'] . ($b['account_no'] ? ' ···' . substr(preg_replace('/\D/', '', (string)$b['account_no']), -4) : '')], $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
        }
        $boards = [];
        try {
            foreach (ProjectLink::pickerProjects($db, $companyId, $userId, $role) as $p) {
                foreach (ProjectLink::pickerBoards($db, $companyId, $userId, $role, $p['id']) as $b) {
                    $boards[] = ['id' => (int)$b['id'], 'label' => $p['label'] . ' · ' . $b['title'], 'project' => $p['label']];
                }
            }
        } catch (Throwable $e) {
            error_log('[receipts] boards: ' . $e->getMessage());
        }
        $locked = null;
        try {
            $locked = $db->query("SELECT MAX(lock_date) FROM gl_period_locks WHERE company_id = " . (int)$companyId . " AND is_active = 1")->fetchColumn() ?: null;
        } catch (Throwable $e) {
        }
        return ['accounts' => $accounts, 'rates' => [['rate' => $rate, 'label' => rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '% VAT'], ['rate' => 0.0, 'label' => 'No VAT']],
                'standard_rate' => $rate, 'banks' => $banks, 'boards' => $boards, 'locked_through' => $locked, 'paid_methods' => RcKit::PAID_METHODS];
    }

    /** A board the person may use (keeps the one already on the bill). */
    public static function boardLabel(PDO $db, int $companyId, int $boardId): string
    {
        $st = $db->prepare("SELECT b.title, p.name, p.project_number FROM project_boards b JOIN projects p ON p.project_id = b.project_id AND p.company_id = b.company_id
                             WHERE b.board_id = ? AND b.company_id = ?");
        $st->execute([$boardId, $companyId]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
        return $b ? (((string)$b['project_number'] !== '' ? $b['project_number'] . ' · ' : '') . $b['name'] . ' · ' . $b['title']) : '';
    }

    // ========================================================= the bill maths

    /**
     * Form lines → Finance bill lines (net unit prices) and the totals in cents.
     * With $incl the prices include VAT: each line's net is the printed amount
     * less its VAT, exact to the cent (a cent or two of discount absorbs the rounding).
     */
    public static function financeLines(array $lines, bool $incl, ?int $boardId): array
    {
        $out = [];
        $netT = 0; $vatT = 0;
        foreach (array_values($lines) as $i => $l) {
            if (!is_array($l)) continue;
            $desc = mb_substr(trim(strip_tags((string)($l['description'] ?? ''))), 0, 255);
            $priceC = RcKit::cents($l['price'] ?? '');
            if ($desc === '' && ($priceC === null || $priceC === 0)) continue;
            $n = count($out) + 1;
            if ($priceC === null) throw new RcProblem("Line $n: enter the amount.", 422);
            if ($priceC < 0) throw new RcProblem("Line $n: an amount cannot be negative — a refund is a supplier credit note in Finance.", 422);
            $qty = is_numeric($l['qty'] ?? null) ? round((float)$l['qty'], 3) : 1.0;
            if ($qty <= 0) throw new RcProblem("Line $n: the quantity must be more than nil.", 422);
            $rate = is_numeric($l['rate'] ?? null) ? round((float)$l['rate'], 2) : 15.0;
            if ($rate < 0 || $rate > 100) throw new RcProblem("Line $n: choose a VAT rate.", 422);
            $grossC = (int)round($qty * $priceC);
            if ($incl) {
                // The net whose VAT (rounded per line, as Finance posts it) lands on the printed amount, when one does.
                $netC = (int)round($grossC * 100 / (100 + $rate));
                $off = abs($netC + (int)round($netC * $rate / 100) - $grossC);
                foreach ([$netC - 1, $netC + 1] as $try) {
                    $d = abs($try + (int)round($try * $rate / 100) - $grossC);
                    if ($try >= 0 && $d < $off) { $netC = $try; $off = $d; }
                }
                $unitC = (int)round($netC / $qty);
                $lineC = (int)round($qty * $unitC);
                while ($lineC < $netC) { $unitC++; $lineC = (int)round($qty * $unitC); }
                $discC = $lineC - $netC;
            } else {
                $netC = $grossC;
                $unitC = $priceC;
                $discC = (int)round($qty * $priceC) - $netC;
            }
            $vatC = (int)round($netC * $rate / 100);
            $out[] = ['description' => $desc !== '' ? $desc : 'Item', 'qty' => $qty, 'unit' => 'ea', 'unit_price' => number_format($unitC / 100, 2, '.', ''),
                      'discount' => number_format($discC / 100, 2, '.', ''), 'tax_rate' => $rate, 'account_id' => (int)($l['account_id'] ?? 0) ?: null,
                      'board_id' => $boardId, 'project_item_id' => $boardId ? ((int)($l['item_id'] ?? 0) ?: null) : null, 'net_c' => $netC, 'vat_c' => $vatC];
            $netT += $netC; $vatT += $vatC;
        }
        if (count($out) > 200) throw new RcProblem('A bill can have up to 200 lines.', 422);
        return ['lines' => $out, 'net' => $netT, 'vat' => $vatT, 'total' => $netT + $vatT];
    }

    // ================================================================ checks

    /**
     * What stands between this form and the books.
     * @return array{checks: array, totals: array, blocked: bool}
     */
    public static function checks(PDO $db, int $companyId, int $userId, string $role, array $row, array $in): array
    {
        $settings = RcKit::settings($db, $companyId);
        $c = [];
        $add = function (string $code, string $level, string $title, string $detail = '', array $extra = []) use (&$c) {
            $c[] = ['code' => $code, 'level' => $level, 'title' => $title, 'detail' => $detail] + $extra;
        };
        $billId = $row['b_id'] ? (int)$row['b_id'] : 0;
        if (in_array($row['b_status'] ?? '', ['posted', 'paid'], true)) {
            $add('posted', 'block', 'This bill is already in the books', 'Correct it with a supplier credit note in Finance.');
        }
        try {
            $calc = self::financeLines((array)($in['lines'] ?? []), !empty($in['incl']), null);
        } catch (RcProblem $e) {
            $add('lines', 'block', $e->getMessage());
            $calc = ['lines' => [], 'net' => 0, 'vat' => 0, 'total' => 0];
        }
        $totals = ['net' => $calc['net'], 'vat' => $calc['vat'], 'total' => $calc['total']];

        // Supplier
        $sid = (int)($in['supplier_id'] ?? 0);
        $sup = $sid ? self::supplierInfo($db, $companyId, $sid) : null;
        if (!$sup || $sup['deleted']) $add('supplier', 'block', 'Choose the supplier', $sid ? 'That supplier is no longer in the CRM.' : 'Pick them from your suppliers, or add a new one.');
        elseif (in_array($sup['status'], ['banned', 'inactive'], true)) $add('supplier_status', 'warn', $sup['name'] . ' is marked ' . $sup['status'] . ' in the CRM', 'Check that you still buy from them.');

        // Number and dates
        $number = trim((string)($in['number'] ?? ''));
        if ($number === '') $add('number', 'block', "Enter the supplier's invoice or slip number", 'It is printed near the top of most slips; it keeps the same slip from being captured twice.');
        $issued = RcKit::ymd($in['issued'] ?? '');
        $due = trim((string)($in['due'] ?? '')) !== '' ? RcKit::ymd($in['due']) : null;
        if (!$issued) $add('date', 'block', 'Enter the date on the slip');
        else {
            if ($issued > date('Y-m-d', strtotime('+1 day'))) $add('date_future', 'warn', 'The date is in the future', 'Check the day and month — slips write the day first.');
            if ($issued < date('Y-m-d', strtotime('-4 years'))) $add('date_old', 'warn', 'The slip is more than four years old', 'SARS allows input VAT up to five years after the invoice date.');
            if ((new PeriodService($db, $companyId))->isLocked($issued)) {
                $add('locked', 'block', 'The books are closed for ' . RcKit::date($issued), 'Ask an admin to reopen the period in Finance, or date it in an open period.');
            }
        }
        if (trim((string)($in['due'] ?? '')) !== '' && !$due) $add('due', 'block', 'The due date is not a valid date');
        if ($due && $issued && $due < $issued) $add('due', 'block', 'The due date is before the slip date');

        // Lines and accounts
        if ($calc['lines'] && $calc['total'] <= 0) $add('lines', 'block', 'The bill comes to nothing', 'Enter the amounts from the slip.');
        if (!$calc['lines'] && !array_filter($c, fn($x) => $x['code'] === 'lines')) $add('lines', 'block', 'Add at least one line', 'One line for the whole slip is fine.');
        $ids = array_values(array_unique(array_filter(array_map(fn($l) => (int)$l['account_id'], $calc['lines']))));
        $accs = [];
        if ($ids) {
            $st = $db->prepare("SELECT account_id, is_active, is_control, account_name FROM gl_accounts WHERE company_id = ? AND account_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
            $st->execute(array_merge([$companyId], $ids));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) $accs[(int)$a['account_id']] = $a;
        }
        $noAcc = 0;
        foreach ($calc['lines'] as $i => $l) {
            if (!$l['account_id']) { $noAcc++; continue; }
            $a = $accs[$l['account_id']] ?? null;
            if (!$a || !(int)$a['is_active']) $add('account', 'block', 'Line ' . ($i + 1) . ': choose an active account');
            elseif ((int)$a['is_control']) $add('account', 'block', 'Line ' . ($i + 1) . ': ' . $a['account_name'] . ' is a control account', 'Bills cannot post to it — choose an expense account.');
        }
        if ($noAcc) $add('account_default', 'warn', $noAcc === 1 ? 'A line has no account' : "$noAcc lines have no account", "It goes to Finance's default expense account. Choosing one keeps your reports right.");

        // Duplicates
        if ($sup && $number !== '') {
            $st = $db->prepare("SELECT b.id, rf.file_id FROM ap_bills b LEFT JOIN receipt_file rf ON rf.bill_id = b.id AND rf.company_id = b.company_id
                                 WHERE b.company_id = ? AND b.supplier_id = ? AND b.vendor_invoice_number = ? AND b.id <> ? AND b.status <> 'blocked' LIMIT 1");
            $st->execute([$companyId, $sid, $number, $billId]);
            if ($d = $st->fetch(PDO::FETCH_ASSOC)) {
                $add('duplicate', 'block', $sup['name'] . "'s invoice $number is already captured", 'Set this receipt aside as a duplicate if it is the same slip.',
                     ['link' => $d['file_id'] ? ['label' => 'Open the other receipt', 'href' => '/receipts/review.php?id=' . (int)$d['file_id']] : ['label' => 'Open the bill in Finance', 'href' => '/finances/#payables?bill=' . (int)$d['id']],
                      'fix' => RcKit::canCapture($role) ? ['op' => 'aside_duplicate', 'label' => 'Set aside as a duplicate'] : null]);
            } elseif ($issued && $calc['total'] > 0) {
                $st = $db->prepare("SELECT vendor_invoice_number FROM ap_bills WHERE company_id = ? AND supplier_id = ? AND issue_date = ? AND ROUND(total * 100) = ? AND id <> ? AND status <> 'blocked' LIMIT 1");
                $st->execute([$companyId, $sid, $issued, $calc['total'], $billId]);
                if (($other = $st->fetchColumn()) !== false) $add('similar', 'warn', 'A bill from ' . $sup['name'] . ' for the same amount on the same day exists', 'Invoice ' . ($other ?: '(no number)') . ' — make sure this is not the same purchase.');
            }
        }

        // The slip's own total
        $slip = RcKit::cents($in['slip_total'] ?? '');
        if ($slip !== null && $slip > 0 && $calc['lines']) {
            $diff = $calc['total'] - $slip;
            if (abs($diff) > 2) {
                $add('slip_total', 'warn', 'The bill comes to ' . RcKit::money($calc['total']) . ', the slip says ' . RcKit::money($slip),
                     ($diff > 0 ? 'That is ' . RcKit::money($diff) . ' more' : 'That is ' . RcKit::money(-$diff) . ' less') . ' than the slip.',
                     count($calc['lines']) === 1 ? ['fix' => ['op' => 'match_total', 'label' => 'Make it ' . RcKit::money($slip)]] : []);
            } elseif ($diff !== 0) {
                $add('rounding', 'info', 'VAT rounding: ' . RcKit::money(abs($diff)) . ($diff > 0 ? ' over' : ' under') . ' the slip', 'VAT is rounded per line, as Finance posts it.');
            }
        }

        // Currency
        if (strtoupper((string)($in['currency'] ?? 'ZAR')) !== 'ZAR') {
            $add('currency', 'block', 'This slip is in ' . strtoupper((string)$in['currency']), 'Finance books bills in rand — enter the rand amounts from your card or bank statement, then switch the currency to ZAR.',
                 ['fix' => ['op' => 'zar', 'label' => 'It is in rand']]);
        }

        // VAT
        if ($sup) {
            $slipVat = preg_replace('/\D/', '', (string)($in['vendor_vat'] ?? ''));
            $supVat = $sup['vat_no'];
            if ($calc['vat'] > 0 && !preg_match('/^4\d{9}$/', $supVat) && !preg_match('/^4\d{9}$/', $slipVat)) {
                $add('vat_number', $settings['require_vat_number'] ? 'block' : 'warn', 'VAT is claimed, but there is no VAT number for ' . $sup['name'],
                     'SARS only allows input VAT on a tax invoice with the supplier’s VAT number. Add it, or do not claim the VAT.',
                     ['fix' => ['op' => 'no_vat', 'label' => 'Don’t claim VAT'], 'overridable' => true]);
            }
            if (preg_match('/^4\d{9}$/', $slipVat)) {
                if ($supVat === '' && RcKit::canCapture($role)) {
                    $add('vat_save', 'info', 'The slip shows VAT number ' . $slipVat, $sup['name'] . ' has none in the CRM.', ['fix' => ['op' => 'save_vat', 'label' => 'Save it on the supplier']]);
                } elseif ($supVat !== '' && $supVat !== $slipVat) {
                    $add('vat_mismatch', 'warn', 'The VAT number on the slip (' . $slipVat . ') is not ' . $sup['name'] . '’s', 'The CRM has ' . $supVat . ' — check that you chose the right supplier.');
                }
            }
            if (!$sup['compliance']['ok']) {
                $add('compliance', $settings['block_expired_compliance'] ? 'block' : 'warn', $sup['name'] . ' is missing compliance documents',
                     implode(', ', $sup['compliance']['missing']) . '.', ['link' => ['label' => 'Open in the CRM', 'href' => $sup['url'] . '#compliance'], 'overridable' => true]);
            }
        }

        // Purchase order
        $poId = (int)($in['po_id'] ?? 0);
        $threshold = (int)round((float)$settings['require_po_over_amount'] * 100);
        if ($poId) {
            $st = $db->prepare("SELECT po_number, total, supplier_id, status FROM purchase_orders WHERE id = ? AND company_id = ?");
            $st->execute([$poId, $companyId]);
            $po = $st->fetch(PDO::FETCH_ASSOC);
            if (!$po || ($sid && (int)$po['supplier_id'] !== $sid) || $po['status'] === 'cancelled') $add('po', 'block', 'That purchase order is not one of this supplier’s open orders');
            elseif ((float)$po['total'] > 0 && $calc['total'] > 0) {
                $var = abs($calc['total'] - (int)round((float)$po['total'] * 100)) / ((float)$po['total'] * 100) * 100;
                if ($var > (float)$settings['variance_tolerance_pct']) {
                    $add('po_variance', 'warn', 'The bill differs from ' . $po['po_number'] . ' by ' . number_format($var, 1) . '%',
                         'The order is for ' . RcKit::money((int)round((float)$po['total'] * 100)) . '; your tolerance is ' . rtrim(rtrim(number_format((float)$settings['variance_tolerance_pct'], 2), '0'), '.') . '%.');
                }
            }
        } elseif ($threshold > 0 && $calc['total'] >= $threshold) {
            $hasPos = (int)$db->query("SELECT COUNT(*) FROM purchase_orders WHERE company_id = " . (int)$companyId)->fetchColumn() > 0;
            $add('po', $hasPos ? 'block' : 'warn', 'Bills of ' . RcKit::money($threshold) . ' or more need a purchase order',
                 $hasPos ? 'Choose the order this bill is for.' : 'No purchase orders exist yet — create them in Procurement, or change the limit in Receipts settings.',
                 ['overridable' => true]);
        }

        // Prices that jumped
        if ($sup && $calc['lines']) {
            $pct = max(5.0, (float)$settings['line_variance_tolerance_pct']);
            $st = $db->prepare("SELECT AVG((bl.quantity * bl.unit_price - bl.discount) / NULLIF(bl.quantity, 0)), COUNT(*) FROM ap_bill_lines bl JOIN ap_bills b ON b.id = bl.bill_id
                                 WHERE b.company_id = ? AND b.supplier_id = ? AND b.status IN ('posted','paid') AND b.id <> ? AND bl.item_description = ? AND b.issue_date >= ?");
            foreach ($calc['lines'] as $l) {
                if ($l['description'] === 'Item' || $l['qty'] <= 0) continue;
                $st->execute([$companyId, $sid, $billId, $l['description'], date('Y-m-d', strtotime('-180 days'))]);
                [$avg, $n] = $st->fetch(PDO::FETCH_NUM);
                $unit = $l['net_c'] / $l['qty'] / 100;
                if ((int)$n > 0 && (float)$avg > 0 && $unit > (float)$avg * (1 + $pct / 100)) {
                    $add('spike', 'info', $l['description'] . ' costs ' . number_format(($unit / (float)$avg - 1) * 100, 0) . '% more than before',
                         'Average ' . RcKit::money((int)round((float)$avg * 100)) . ' excl. VAT over the last six months, now ' . RcKit::money((int)round($unit * 100)) . '.');
                }
            }
        }

        // Paid on the spot
        $paid = (array)($in['paid'] ?? []);
        if (($paid['method'] ?? '') !== '') {
            if (!isset(RcKit::PAID_METHODS[$paid['method']])) $add('paid', 'block', 'Choose how it was paid');
            $pd = RcKit::ymd($paid['date'] ?? '');
            if (!$pd) $add('paid', 'block', 'Enter the date it was paid');
            elseif ($pd > date('Y-m-d', strtotime('+1 day'))) $add('paid', 'block', 'A payment cannot be dated in the future');
            elseif ((new PeriodService($db, $companyId))->isLocked($pd)) $add('paid', 'block', 'The books are closed for the payment date ' . RcKit::date($pd));
        }

        // Admin overrides
        $over = self::overrides($db, $companyId, (int)$row['file_id']);
        foreach ($c as &$x) {
            if (!empty($x['overridable']) && $x['level'] === 'block') {
                if (isset($over[$x['code']])) {
                    $x['level'] = 'warn';
                    $x['overridden'] = $over[$x['code']];
                } elseif (RcKit::canOverride($role)) {
                    $x['can_override'] = true;
                }
            }
        }
        unset($x);
        $order = ['block' => 0, 'warn' => 1, 'info' => 2];
        usort($c, fn($a, $b) => $order[$a['level']] <=> $order[$b['level']]);
        return ['checks' => $c, 'totals' => $totals, 'blocked' => (bool)array_filter($c, fn($x) => $x['level'] === 'block')];
    }

    private static function overrides(PDO $db, int $companyId, int $fileId): array
    {
        $st = $db->prepare("SELECT code, override_by, override_reason, override_at FROM receipt_policy_log WHERE company_id = ? AND file_id = ? AND override_by IS NOT NULL ORDER BY id");
        $st->execute([$companyId, $fileId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $out[$o['code']] = ['by' => RcKit::personName($db, (int)$o['override_by']), 'reason' => (string)$o['override_reason'], 'at' => $o['override_at']];
        }
        return $out;
    }

    /** An admin posts past a block, with a reason that stays on record. */
    public static function override(PDO $db, int $companyId, int $userId, string $role, int $fileId, string $code, string $reason): void
    {
        if (!RcKit::canOverride($role)) throw new RcForbidden('Only an admin can override a check.');
        if (!in_array($code, self::OVERRIDABLE, true)) throw new RcProblem('That check cannot be overridden.', 422);
        $reason = mb_substr(trim($reason), 0, 500);
        if (mb_strlen($reason) < 4) throw new RcProblem('Say why — the reason stays on record.', 422);
        $row = self::need($db, $companyId, $userId, $role, $fileId);
        $labels = ['compliance' => 'Supplier compliance documents', 'po' => 'Purchase order required', 'vat_number' => 'VAT claimed without a VAT number'];
        $db->prepare("DELETE FROM receipt_policy_log WHERE company_id = ? AND file_id = ? AND code = ?")->execute([$companyId, $fileId, $code]);
        $db->prepare("INSERT INTO receipt_policy_log (company_id, file_id, code, message, severity, override_by, override_reason, override_at, created_at) VALUES (?, ?, ?, ?, 'error', ?, ?, NOW(), NOW())")
           ->execute([$companyId, $fileId, $code, $labels[$code], $userId, $reason]);
        RcKit::audit($db, $companyId, $userId, 'receipt_check_overridden', $fileId, ['code' => $code, 'reason' => $reason, 'bill_id' => $row['b_id']]);
    }

    /** Keep receipt_policy_log in line with the latest checks (overrides stay). */
    private static function logChecks(PDO $db, int $companyId, int $fileId, array $checks): void
    {
        $db->prepare("DELETE FROM receipt_policy_log WHERE company_id = ? AND file_id = ? AND override_by IS NULL")->execute([$companyId, $fileId]);
        $ins = $db->prepare("INSERT INTO receipt_policy_log (company_id, file_id, code, message, severity, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        foreach ($checks as $c) {
            if ($c['level'] === 'info' || !empty($c['overridden'])) continue;
            $ins->execute([$companyId, $fileId, mb_substr($c['code'], 0, 64), mb_substr($c['title'], 0, 255), $c['level'] === 'block' ? 'error' : 'warn']);
        }
    }

    // ================================================================= saving

    /** Save the bill as a draft (Finance status 'review'). @return array{bill_id:int, checks:array} */
    public static function save(PDO $db, int $companyId, int $userId, string $role, int $fileId, array $in): array
    {
        if (!RcKit::canCapture($role)) throw new RcForbidden('Only admins and bookkeepers can make bills from receipts.');
        $ctx = Fin::atomic($db, fn() => self::saveInTx($db, $companyId, $userId, $role, $fileId, $in));
        self::afterSave($db, $companyId, $userId, $fileId, $ctx);
        RcKit::audit($db, $companyId, $userId, $ctx['created'] ? 'receipt_bill_created' : 'receipt_bill_saved', $fileId, ['bill_id' => $ctx['bill_id'], 'total_cents' => $ctx['total']]);
        return ['bill_id' => $ctx['bill_id'], 'checks' => $ctx['checks']];
    }

    /**
     * Save, check and post — and record the payment when it was paid on the spot.
     * The draft is saved first (and kept when a check stops the posting); the
     * posting runs in its own transaction with the checks run again under the lock.
     */
    public static function post(PDO $db, int $companyId, int $userId, string $role, int $fileId, array $in): array
    {
        if (!RcKit::canCapture($role)) throw new RcForbidden('Only admins and bookkeepers can post bills.');
        $ctx = Fin::atomic($db, fn() => self::saveInTx($db, $companyId, $userId, $role, $fileId, $in));
        self::afterSave($db, $companyId, $userId, $fileId, $ctx);
        if ($ctx['blocked']) throw new RcBlocked('Sort out the checks marked in red before posting. The draft is saved.', $ctx['checks']);
        $ctx = Fin::atomic($db, function () use ($db, $companyId, $userId, $role, $fileId, $in, $ctx) {
            $row = self::need($db, $companyId, $userId, $role, $fileId, true);
            if ((int)$row['b_id'] !== $ctx['bill_id'] || in_array($row['b_status'], ['posted', 'paid'], true)) throw new RcProblem('This bill changed while you were posting it — reload and try again.', 409);
            $checks = self::checks($db, $companyId, $userId, $role, $row, $in);
            if ($checks['blocked']) throw new RcBlocked('Sort out the checks marked in red before posting.', $checks['checks']);
            try {
                FinPayables::postBill($db, $companyId, $userId, $role, $ctx['bill_id']);
                $st = $db->prepare("SELECT total, journal_id FROM ap_bills WHERE id = ? AND company_id = ?");
                $st->execute([$ctx['bill_id'], $companyId]);
                [$total, $journal] = $st->fetch(PDO::FETCH_NUM);
                $ctx['journal_id'] = (int)$journal;
                $paid = (array)($in['paid'] ?? []);
                if (($paid['method'] ?? '') !== '') {
                    $pay = FinPayables::pay($db, $companyId, $userId, $role, [
                        'supplier_id' => $ctx['supplier_id'], 'date' => $paid['date'], 'method' => $paid['method'],
                        'bank_account_id' => (int)($paid['bank_account_id'] ?? 0) ?: null, 'reference' => trim((string)($in['number'] ?? '')),
                        'notes' => 'Paid on the spot — receipt #' . $fileId, 'key' => 'rcpt-' . $fileId . '-' . $ctx['bill_id'],
                        'allocations' => [['bill_id' => $ctx['bill_id'], 'amount' => $total]],
                    ]);
                    $ctx['payment_id'] = (int)$pay['payment_id'];
                }
            } catch (FinUserError $e) {
                throw new RcProblem($e->getMessage(), 409);
            }
            $db->prepare("DELETE FROM receipt_policy_log WHERE company_id = ? AND file_id = ? AND override_by IS NULL AND severity = 'error'")->execute([$companyId, $fileId]);
            return $ctx;
        });
        $costs = self::writeProjectCosts($db, $companyId, $userId, $role, $ctx['bill_id']);
        RcKit::audit($db, $companyId, $userId, 'receipt_bill_posted', $fileId, ['bill_id' => $ctx['bill_id'], 'journal_id' => $ctx['journal_id'], 'payment_id' => $ctx['payment_id'], 'total_cents' => $ctx['total']]);
        $row = self::row($db, $companyId, $fileId);
        if ($row) {
            RcKit::notify($db, $companyId, (int)$row['uploaded_by'], $userId, 'receipt_posted', 'Your receipt is in the books',
                          trim(($ctx['supplier'] ?: 'A receipt') . ' · ' . RcKit::money($ctx['total'])), '/receipts/review.php?id=' . $fileId);
        }
        return ['bill_id' => $ctx['bill_id'], 'journal_id' => $ctx['journal_id'], 'payment_id' => $ctx['payment_id'], 'project_costs' => $costs, 'state' => $row['state'] ?? 'posted',
                'checks' => array_values(array_filter($ctx['checks'], fn($c) => $c['level'] !== 'block'))];
    }

    private static function saveInTx(PDO $db, int $companyId, int $userId, string $role, int $fileId, array $in): array
    {
        $row = self::need($db, $companyId, $userId, $role, $fileId, true);
        if ($row['dismissed_at']) throw new RcProblem('This receipt is set aside. Bring it back first.', 409);
        if (in_array($row['b_status'], ['posted', 'paid'], true)) throw new RcProblem('This bill is already in the books. Correct it with a supplier credit note in Finance.', 409);
        $sid = (int)($in['supplier_id'] ?? 0);
        $st = $db->prepare("SELECT name FROM crm_accounts WHERE id = ? AND company_id = ? AND type = 'supplier' AND deleted_at IS NULL");
        $st->execute([$sid, $companyId]);
        $supplierName = $st->fetchColumn();
        if ($supplierName === false) throw new RcProblem('Choose the supplier.', 422);

        // Project board: one the person may open (the bill's own board stays allowed).
        $boardId = (int)($in['board_id'] ?? 0) ?: null;
        if ($boardId) {
            $current = null;
            if ($row['b_id']) {
                $q = $db->prepare("SELECT project_board_id FROM ap_bill_lines WHERE bill_id = ? AND project_board_id IS NOT NULL LIMIT 1");
                $q->execute([(int)$row['b_id']]);
                $current = (int)$q->fetchColumn() ?: null;
            }
            if (!ProjectLink::boardBelongs($db, $companyId, $boardId) || ($boardId !== $current && !ProjectLink::canSeeBoard($db, $companyId, $userId, $role, $boardId))) {
                throw new RcProblem('That project board is not one you can use.', 422);
            }
            foreach ((array)($in['lines'] ?? []) as $l) {
                $item = (int)($l['item_id'] ?? 0);
                if ($item && !ProjectLink::boardBelongs($db, $companyId, $boardId, $item)) throw new RcProblem('A line’s task is not on the chosen project board.', 422);
            }
        }
        // Purchase order of this supplier
        $poId = (int)($in['po_id'] ?? 0) ?: null;
        if ($poId) {
            $q = $db->prepare("SELECT 1 FROM purchase_orders WHERE id = ? AND company_id = ? AND supplier_id = ? AND status <> 'cancelled'");
            $q->execute([$poId, $companyId, $sid]);
            if (!$q->fetchColumn()) throw new RcProblem('That purchase order is not one of this supplier’s open orders.', 422);
        }
        // Paid on the spot
        $paid = (array)($in['paid'] ?? []);
        $method = (string)($paid['method'] ?? '');
        $paidDate = null; $bank = null;
        if ($method !== '') {
            if (!isset(RcKit::PAID_METHODS[$method])) throw new RcProblem('Choose how it was paid.', 422);
            $paidDate = RcKit::ymd($paid['date'] ?? '');
            if (!$paidDate) throw new RcProblem('Enter the date it was paid.', 422);
            $bank = (int)($paid['bank_account_id'] ?? 0) ?: null;
            if ($bank) {
                $q = $db->prepare("SELECT 1 FROM gl_bank_accounts WHERE id = ? AND company_id = ? AND is_active = 1");
                $q->execute([$bank, $companyId]);
                if (!$q->fetchColumn()) throw new RcProblem('Choose one of your bank accounts.', 422);
            }
        }
        $incl = !empty($in['incl']);
        $calc = self::financeLines((array)($in['lines'] ?? []), $incl, $boardId);
        if (!$calc['lines']) throw new RcProblem('Add at least one line — one line for the whole slip is fine.', 422);
        $slipVat = preg_replace('/\D/', '', (string)($in['vendor_vat'] ?? ''));
        $vat = preg_match('/^4\d{9}$/', $slipVat) ? $slipVat : '';
        if ($vat === '') {
            $sv = self::supplierVat($db, $companyId, $sid);
            if (preg_match('/^4\d{9}$/', $sv)) $vat = $sv;
        }
        $created = !$row['b_id'];
        try {
            $billId = FinPayables::saveBill($db, $companyId, $userId, $role, [
                'id' => (int)($row['b_id'] ?? 0), 'supplier_id' => $sid, 'number' => (string)($in['number'] ?? ''), 'vendor_vat' => $vat,
                'issued' => (string)($in['issued'] ?? ''), 'due' => (string)($in['due'] ?? ''), 'notes' => (string)($in['notes'] ?? ''),
                'lines' => array_map(fn($l) => array_diff_key($l, ['net_c' => 1, 'vat_c' => 1]), $calc['lines']),
            ]);
        } catch (FinUserError $e) {
            throw new RcProblem($e->getMessage(), $e->getCode() === 409 ? 409 : 422);
        }
        $db->prepare("UPDATE ap_bills SET status = 'review', file_id = ?, ocr_id = ? WHERE id = ? AND company_id = ? AND status IN ('draft','review','approved')")
           ->execute([$fileId, $row['ocr_id'] ?: null, $billId, $companyId]);
        $st = $db->prepare("SELECT * FROM ap_bill_lines WHERE bill_id = ? ORDER BY sort_order, id");
        $st->execute([$billId]);
        $form = ['incl' => $incl, 'slip_total' => RcKit::cents($in['slip_total'] ?? '') !== null ? RcKit::cents($in['slip_total']) / 100 : null,
                 'lines' => array_map(fn($l) => ['description' => mb_substr(trim((string)($l['description'] ?? '')), 0, 255), 'qty' => is_numeric($l['qty'] ?? null) ? (float)$l['qty'] : 1.0,
                                                 'price' => RcKit::cents($l['price'] ?? '') !== null ? RcKit::cents($l['price']) / 100 : 0, 'rate' => (float)($l['rate'] ?? 0),
                                                 'account_id' => (int)($l['account_id'] ?? 0) ?: null, 'account_source' => '', 'item_id' => $boardId ? ((int)($l['item_id'] ?? 0) ?: null) : null],
                                    array_values(array_filter((array)($in['lines'] ?? []), fn($l) => is_array($l) && (trim((string)($l['description'] ?? '')) !== '' || (RcKit::cents($l['price'] ?? '') ?? 0) !== 0)))),
                 'hash' => self::billHash($st->fetchAll(PDO::FETCH_ASSOC))];
        $db->prepare("UPDATE receipt_file SET bill_id = ?, po_id = ?, paid_method = ?, paid_date = ?, paid_bank_account_id = ?, form_json = ? WHERE file_id = ? AND company_id = ?")
           ->execute([$billId, $poId, $method !== '' ? $method : null, $paidDate, $bank, json_encode($form, JSON_UNESCAPED_UNICODE), $fileId, $companyId]);
        $row = self::row($db, $companyId, $fileId);
        $checks = self::checks($db, $companyId, $userId, $role, $row, $in);
        self::logChecks($db, $companyId, $fileId, $checks['checks']);
        return ['bill_id' => $billId, 'created' => $created, 'checks' => $checks['checks'], 'blocked' => $checks['blocked'], 'total' => $calc['total'],
                'supplier' => (string)$supplierName, 'supplier_id' => $sid, 'vendor' => (string)($row['vendor_name'] ?? ''), 'lines' => $calc['lines'], 'board_id' => $boardId,
                'journal_id' => null, 'payment_id' => null];
    }

    /** After the transaction: learning, and the slip on the bill in Finance. */
    private static function afterSave(PDO $db, int $companyId, int $userId, int $fileId, array $ctx): void
    {
        try {
            if ($ctx['vendor'] !== '') RcLearn::rememberSupplier($db, $companyId, $ctx['vendor'], $ctx['supplier_id']);
            RcLearn::rememberBill($db, $companyId, $ctx['supplier_id'], $ctx['lines'], $ctx['board_id']);
        } catch (Throwable $e) {
            error_log('[receipts] learning: ' . $e->getMessage());
        }
        self::attachToBill($db, $companyId, $userId, $fileId, $ctx['bill_id']);
    }

    /** The slip as a supporting document on the bill in Finance (once). */
    public static function attachToBill(PDO $db, int $companyId, int $userId, int $fileId, int $billId): void
    {
        try {
            $row = self::row($db, $companyId, $fileId);
            $path = $row ? RcFiles::path($companyId, $row['path']) : null;
            if (!$path) return;
            FinFiles::attachCopy($db, $companyId, $userId, 'bill', $billId, $path, (string)($row['original_name'] ?: ('receipt-' . $fileId . '.' . (RcFiles::TYPES[$row['mime']] ?? 'pdf'))));
        } catch (Throwable $e) {
            error_log('[receipts] attach to bill ' . $billId . ': ' . $e->getMessage());
        }
    }

    /** Delete the draft bill and keep the receipt. */
    public static function deleteDraft(PDO $db, int $companyId, int $userId, string $role, int $fileId): void
    {
        if (!RcKit::canCapture($role)) throw new RcForbidden('Only admins and bookkeepers can delete bills.');
        Fin::atomic($db, function () use ($db, $companyId, $userId, $role, $fileId) {
            $row = self::need($db, $companyId, $userId, $role, $fileId, true);
            if (!$row['b_id']) return;
            try {
                FinPayables::deleteBill($db, $companyId, $userId, $role, (int)$row['b_id']);
            } catch (FinUserError $e) {
                throw new RcProblem($e->getMessage(), 409);
            }
            $db->prepare("UPDATE receipt_file SET bill_id = NULL, form_json = NULL WHERE file_id = ? AND company_id = ?")->execute([$fileId, $companyId]);
            $db->prepare("DELETE FROM receipt_policy_log WHERE company_id = ? AND file_id = ? AND override_by IS NULL")->execute([$companyId, $fileId]);
            RcKit::audit($db, $companyId, $userId, 'receipt_bill_deleted', $fileId, ['bill_id' => (int)$row['b_id']]);
        });
    }

    // ========================================================= project costs

    /**
     * The net cost of each line on a project board, added to the board's cost
     * column as the person posting (formulas, mirrors and automations follow).
     * Without a cost column, or when they may not edit the board, the cost is
     * noted in the item's activity instead. Never undoes the posting.
     */
    public static function writeProjectCosts(PDO $db, int $companyId, int $userId, string $role, int $billId): array
    {
        $out = [];
        try {
            $st = $db->prepare("SELECT quantity, unit_price, discount, project_board_id, project_item_id FROM ap_bill_lines WHERE bill_id = ?");
            $st->execute([$billId]);
            $alloc = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
                if (!$l['project_board_id'] || !$l['project_item_id']) continue;
                $net = (float)$l['quantity'] * (float)$l['unit_price'] - (float)$l['discount'];
                $alloc[(int)$l['project_board_id']][(int)$l['project_item_id']] = ($alloc[(int)$l['project_board_id']][(int)$l['project_item_id']] ?? 0.0) + $net;
            }
            if (!$alloc) return [];
            $st = $db->prepare("SELECT b.vendor_invoice_number, a.name FROM ap_bills b LEFT JOIN crm_accounts a ON a.id = b.supplier_id AND a.company_id = b.company_id WHERE b.id = ? AND b.company_id = ?");
            $st->execute([$billId, $companyId]);
            $b = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $label = 'Bill ' . ((string)($b['vendor_invoice_number'] ?? '') !== '' ? $b['vendor_invoice_number'] : '#' . $billId) . (!empty($b['name']) ? ' · ' . $b['name'] : '');
            $items = new ItemRepo(ProjectLink::repo($db, $companyId, $userId, $role));
            foreach ($alloc as $boardId => $perItem) {
                $col = ProjectLink::boardBelongs($db, $companyId, $boardId) ? self::costColumn($db, $companyId, $boardId) : null;
                foreach ($perItem as $itemId => $amount) {
                    $amount = round($amount, 2);
                    $entry = ['board_id' => $boardId, 'item_id' => $itemId, 'amount' => $amount, 'column' => null, 'url' => ProjectLink::itemUrl($boardId, $itemId)];
                    if (!ProjectLink::boardBelongs($db, $companyId, $boardId, $itemId)) { $out[] = $entry + ['status' => 'invalid']; continue; }
                    $reason = 'no_cost_column';
                    if ($col) {
                        try {
                            $q = $db->prepare("SELECT value FROM board_item_values WHERE item_id = ? AND column_id = ?");
                            $q->execute([$itemId, (int)$col['column_id']]);
                            $was = $q->fetchColumn();
                            $items->setValue($itemId, (int)$col['column_id'], number_format((is_numeric($was) ? (float)$was : 0.0) + $amount, 2, '.', ''));
                            $out[] = ['column' => $col['name'], 'status' => 'written'] + $entry;
                            continue;
                        } catch (ProjectUserError $e) {
                            $reason = 'no_access';
                        } catch (Throwable $e) {
                            error_log('[receipts] cost write-back (item ' . $itemId . '): ' . $e->getMessage());
                            $reason = 'error';
                        }
                    }
                    Activity::log($db, $companyId, $userId, $boardId, $itemId, 'cost_saved', ['description' => Activity::clip($label, 80), 'amount' => $amount,
                                  'source' => 'receipts', 'bill_id' => $billId, 'reason' => $reason]);
                    $out[] = $entry + ['status' => 'noted', 'reason' => $reason];
                }
            }
        } catch (Throwable $e) {
            error_log('[receipts] project costs: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * The board column receipt costs add up in: a plain number column named like
     * "Actual cost" / "Cost" / "Amount" / "Spent", else one formatted as currency.
     * Rates, prices, quantities, budgets, estimates and percentages are never used.
     */
    private static function costColumn(PDO $db, int $companyId, int $boardId): ?array
    {
        $st = $db->prepare("SELECT column_id, name, type, config FROM board_columns WHERE board_id = ? AND company_id = ? AND type = 'number' ORDER BY position, column_id");
        $st->execute([$boardId, $companyId]);
        $best = null;
        $bestScore = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $name = (string)$col['name'];
            $format = Columns::config($col)['format'];
            if ($format === 'percent' || preg_match('/\b(unit|rate|price|qty|quantit|budget|estimat|forecast|quot|planned|margin|markup|varian|percent)|%/i', $name)) continue;
            if (preg_match('/actual\s*cost/i', $name)) $score = 4;
            elseif (preg_match('/cost/i', $name)) $score = 3;
            elseif (preg_match('/amount|spent/i', $name)) $score = 2;
            elseif ($format === 'currency') $score = 1;
            else continue;
            if ($score > $bestScore) { $best = $col; $bestScore = $score; }
        }
        return $best;
    }
}
