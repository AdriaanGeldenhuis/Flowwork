<?php
// finances/lib/FinJournals.php
//
// Manual journals and the journal register (every journal any module posts).
//
// Lifecycle of a manual journal: draft → approved → posted. When the company
// switches the approval step off, a writer may post a draft directly (it is
// approved and posted by the same person in one step). Posted journals are
// never edited or deleted: a correction is a reversing journal (the original
// stays, linked both ways). System journals (invoices, bills, payments,
// payroll, depreciation, bank matches, VAT, year end) belong to their own
// documents and are reversed through those flows, not here.
//
// Checks on top of the posting engine's own:
//   - a draft is only editable / approvable / postable when it is a manual
//     journal (a superseded engine draft would count its document twice),
//   - lines: two or more, positive amounts on one side only, integer-cents
//     balance, accounts that exist, are active and allow manual journals,
//     tax codes and project tags of this company only,
//   - period locks on create, edit, approve, post and reverse,
//   - segregation of duties (finance_require_sod): the approver may neither
//     have prepared nor edited the journal,
//   - company rules: a description, and a supporting document before posting,
//   - posting re-checks everything inside one transaction with the journal
//     row locked, so nothing can change between the check and the post.
// Every step is in the audit trail (entity 'journal').

require_once __DIR__ . '/Fin.php';
require_once __DIR__ . '/PeriodService.php';
require_once __DIR__ . '/ReversalService.php';
require_once __DIR__ . '/InventoryService.php';
require_once __DIR__ . '/Sequence.php';
require_once __DIR__ . '/FinanceBootstrap.php';
require_once __DIR__ . '/FinOverview.php';

final class FinJournals
{
    /** Journals that belong to their documents and are reversed through their own flows. */
    public const ENGINE_MODULES = ['payroll', 'vat_settle', 'vat_adjust', 'vat_pay', 'year_end', 'bad_debt'];
    public const ENGINE_REF_TYPES = [
        'invoice', 'payment', 'credit_note', 'ap_bill', 'ap_payment', 'vendor_credit', 'pay_run', 'depreciation',
        'fa_disposal', 'bank_tx', 'reversal', 'invoice_write_off', 'vat_adjustment', 'vat_period', 'inventory_adjust', 'inventory_return',
    ];

    /** Where a journal came from, for filters and labels. */
    public const SOURCES = [
        'manual' => 'Manual', 'sales' => 'Sales', 'receipts' => 'Customer payments', 'purchases' => 'Purchases', 'payments' => 'Supplier payments',
        'bank' => 'Bank', 'payroll' => 'Payroll', 'assets' => 'Fixed assets', 'vat' => 'VAT', 'inventory' => 'Inventory',
        'year_end' => 'Year end', 'reversal' => 'Reversals', 'other' => 'Other',
    ];

    private const SOURCE_SQL = [
        'manual'    => "((je.module IN ('manual','') OR je.module IS NULL) AND (je.ref_type IS NULL OR je.ref_type = ''))",
        'sales'     => "je.ref_type IN ('invoice','invoice_write_off','credit_note')",
        'receipts'  => "je.ref_type = 'payment'",
        'purchases' => "je.ref_type IN ('ap_bill','vendor_credit')",
        'payments'  => "je.ref_type = 'ap_payment'",
        'bank'      => "je.ref_type = 'bank_tx'",
        'payroll'   => "(je.module = 'payroll' OR je.ref_type = 'pay_run')",
        'assets'    => "je.ref_type IN ('depreciation','fa_disposal')",
        'vat'       => "je.module IN ('vat_adjust','vat_settle','vat_pay')",
        'inventory' => "je.ref_type IN ('inventory_adjust','inventory_return')",
        'year_end'  => "je.module = 'year_end'",
        'reversal'  => "je.ref_type = 'reversal'",
    ];

    public static function sourceOf(array $j): string
    {
        $rt = (string)($j['ref_type'] ?? '');
        $m = (string)($j['module'] ?? '');
        if ($rt === 'reversal') return 'reversal';
        if (in_array($rt, ['invoice', 'invoice_write_off', 'credit_note'], true)) return 'sales';
        if ($rt === 'payment') return 'receipts';
        if (in_array($rt, ['ap_bill', 'vendor_credit'], true)) return 'purchases';
        if ($rt === 'ap_payment') return 'payments';
        if ($rt === 'bank_tx') return 'bank';
        if ($m === 'payroll' || $rt === 'pay_run') return 'payroll';
        if (in_array($rt, ['depreciation', 'fa_disposal'], true)) return 'assets';
        if (in_array($m, ['vat_adjust', 'vat_settle', 'vat_pay'], true)) return 'vat';
        if (in_array($rt, ['inventory_adjust', 'inventory_return'], true)) return 'inventory';
        if ($m === 'year_end') return 'year_end';
        return self::isManual($j) ? 'manual' : 'other';
    }

    /** A journal people keep by hand (as opposed to one a document posted). */
    public static function isManual(array $j): bool
    {
        $m = (string)($j['module'] ?? '');
        $rt = (string)($j['ref_type'] ?? '');
        $st = (string)($j['source_type'] ?? '');
        return in_array($m, ['manual', ''], true) && $rt === '' && in_array($st, ['', 'manual'], true);
    }

    public static function isEngine(array $j): bool
    {
        return in_array((string)($j['module'] ?? ''), self::ENGINE_MODULES, true) || in_array((string)($j['ref_type'] ?? ''), self::ENGINE_REF_TYPES, true);
    }

    // -------------------------------------------------------------- reading

    /**
     * The journal register. $f: status (all|draft|approved|posted|reversed|system), source, from, to,
     * q (reference / description / amount), account (code), min, max, page, per, sort (date|amount|ref), dir.
     */
    public static function list(PDO $db, int $companyId, array $f): array
    {
        $where = ['je.company_id = ?'];
        $args = [$companyId];
        $from = Fin::ymd($f['from'] ?? '');
        $to = Fin::ymd($f['to'] ?? '');
        if ($from) { $where[] = 'je.entry_date >= ?'; $args[] = $from; }
        if ($to) { $where[] = 'je.entry_date <= ?'; $args[] = $to; }
        $source = (string)($f['source'] ?? '');
        if (isset(self::SOURCE_SQL[$source])) $where[] = self::SOURCE_SQL[$source];
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $amt = Fin::cents($q);
            $like = '%' . Fin::likeEscape($q) . '%';
            if ($amt !== null && preg_match('/\d/', $q) && !preg_match('/[a-z]/i', $q)) {
                $where[] = "(je.reference LIKE ? OR je.description LIKE ? OR EXISTS (SELECT 1 FROM journal_lines q1 WHERE q1.journal_id = je.id AND (ROUND(q1.debit * 100) = ? OR ROUND(q1.credit * 100) = ?)))";
                array_push($args, $like, $like, abs($amt), abs($amt));
            } else {
                $where[] = "(je.reference LIKE ? OR je.description LIKE ? OR EXISTS (SELECT 1 FROM journal_lines q2 WHERE q2.journal_id = je.id AND q2.description LIKE ?))";
                array_push($args, $like, $like, $like);
            }
        }
        $acc = trim((string)($f['account'] ?? ''));
        if ($acc !== '') { $where[] = 'EXISTS (SELECT 1 FROM journal_lines a1 WHERE a1.journal_id = je.id AND a1.account_code = ?)'; $args[] = $acc; }
        $min = Fin::cents($f['min'] ?? '');
        $max = Fin::cents($f['max'] ?? '');
        $base = implode(' AND ', $where);

        // status chips (counted before the status filter)
        $counts = ['all' => 0, 'draft' => 0, 'approved' => 0, 'posted' => 0, 'reversed' => 0];
        $st = $db->prepare("SELECT SUM(je.status IN ('draft','prepared')) d, SUM(je.status = 'approved') a,
                                   SUM(je.status = 'posted' AND je.reversed_by_journal_id IS NULL) p,
                                   SUM(je.status = 'posted' AND je.reversed_by_journal_id IS NOT NULL) r, COUNT(*) n
                              FROM journal_entries je WHERE $base");
        $st->execute($args);
        $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $counts = ['all' => (int)($c['n'] ?? 0), 'draft' => (int)($c['d'] ?? 0), 'approved' => (int)($c['a'] ?? 0), 'posted' => (int)($c['p'] ?? 0), 'reversed' => (int)($c['r'] ?? 0)];

        $status = (string)($f['status'] ?? 'all');
        if ($status === 'draft') $where[] = "je.status IN ('draft','prepared')";
        elseif ($status === 'approved') $where[] = "je.status = 'approved'";
        elseif ($status === 'posted') $where[] = "je.status = 'posted' AND je.reversed_by_journal_id IS NULL";
        elseif ($status === 'reversed') $where[] = "je.status = 'posted' AND je.reversed_by_journal_id IS NOT NULL";
        $sql = implode(' AND ', $where);
        $having = [];
        $hArgs = [];
        if ($min !== null) { $having[] = 'total_cents >= ?'; $hArgs[] = $min; }
        if ($max !== null) { $having[] = 'total_cents <= ?'; $hArgs[] = $max; }
        $havingSql = $having ? 'HAVING ' . implode(' AND ', $having) : '';

        $per = Fin::clampInt($f['per'] ?? 50, 10, 200);
        $page = max(1, (int)($f['page'] ?? 1));
        $dir = strtolower((string)($f['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $order = ['date' => "je.entry_date $dir, je.id $dir", 'amount' => "total_cents $dir, je.id DESC", 'ref' => "je.reference $dir, je.id DESC"][$f['sort'] ?? 'date'] ?? "je.entry_date DESC, je.id DESC";

        $inner = "SELECT je.id, je.entry_date, je.reference, je.description, je.module, je.ref_type, je.ref_id, je.source_type, je.source_id,
                         je.status, je.created_by, je.created_at, je.approved_by, je.posted_by, je.reversed_by_journal_id, je.reverses_journal_id,
                         COALESCE((SELECT SUM(ROUND(l.debit * 100)) FROM journal_lines l WHERE l.journal_id = je.id), 0) total_cents,
                         (SELECT COUNT(*) FROM journal_lines l2 WHERE l2.journal_id = je.id) line_count,
                         (SELECT GROUP_CONCAT(DISTINCT l3.account_code ORDER BY l3.id SEPARATOR ',') FROM journal_lines l3 WHERE l3.journal_id = je.id) codes
                    FROM journal_entries je WHERE $sql $havingSql";
        $st = $db->prepare("SELECT COUNT(*), COALESCE(SUM(total_cents), 0) FROM ($inner) t");
        $st->execute(array_merge($args, $hArgs));
        [$total, $sumCents] = $st->fetch(PDO::FETCH_NUM);
        $st = $db->prepare("$inner ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
        $st->execute(array_merge($args, $hArgs));
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $codes = array_slice(array_filter(explode(',', (string)$r['codes'])), 0, 4);
            $rows[] = [
                'id' => (int)$r['id'], 'date' => $r['entry_date'], 'reference' => (string)$r['reference'], 'description' => (string)$r['description'],
                'status' => FinJournalsStatus::of($r), 'source' => self::sourceOf($r), 'manual' => self::isManual($r),
                'total' => ((int)$r['total_cents']) / 100, 'lines' => (int)$r['line_count'], 'codes' => $codes,
                'created_by' => Fin::personName($db, $companyId, $r['created_by']), 'created_at' => $r['created_at'],
            ];
        }
        return ['rows' => $rows, 'total' => (int)$total, 'sum' => ((int)$sumCents) / 100, 'page' => $page, 'per' => $per, 'counts' => $counts];
    }

    /** One journal with its lines, people, links, attachments, trail and what the person may do with it. */
    public static function get(PDO $db, int $companyId, int $userId, string $role, int $id): array
    {
        $st = $db->prepare("SELECT * FROM journal_entries WHERE id = ? AND company_id = ?");
        $st->execute([$id, $companyId]);
        $j = $st->fetch(PDO::FETCH_ASSOC);
        if (!$j) throw new FinUserError('That journal was not found.');
        $st = $db->prepare("SELECT jl.id, jl.account_code, jl.description, jl.debit, jl.credit, jl.tax_code_id, jl.project_id, jl.board_id, jl.item_id,
                                   jl.customer_id, jl.supplier_id, ga.account_name, ga.account_type, tc.code tax_code
                              FROM journal_lines jl
                         LEFT JOIN gl_accounts ga ON ga.company_id = ? AND ga.account_code = jl.account_code
                         LEFT JOIN gl_tax_codes tc ON tc.tax_code_id = jl.tax_code_id AND tc.company_id = ?
                             WHERE jl.journal_id = ? ORDER BY jl.id");
        $st->execute([$companyId, $companyId, $id]);
        $lines = [];
        $dc = 0; $cc = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $dc += (int)round((float)$l['debit'] * 100);
            $cc += (int)round((float)$l['credit'] * 100);
            $lines[] = [
                'id' => (int)$l['id'], 'account_code' => (string)$l['account_code'], 'account_name' => (string)($l['account_name'] ?? ''), 'type' => (string)($l['account_type'] ?? ''),
                'description' => (string)$l['description'], 'debit' => round((float)$l['debit'], 2), 'credit' => round((float)$l['credit'], 2),
                'tax_code_id' => $l['tax_code_id'] ? (int)$l['tax_code_id'] : null, 'tax_code' => (string)($l['tax_code'] ?? ''),
                'project_id' => $l['project_id'] ? (int)$l['project_id'] : null, 'board_id' => $l['board_id'] ? (int)$l['board_id'] : null, 'item_id' => $l['item_id'] ? (int)$l['item_id'] : null,
            ];
        }
        $status = FinJournalsStatus::of($j);
        $manual = self::isManual($j);
        $writer = Fin::canWrite($role);
        $cfg = Fin::config($db, $companyId);
        $sod = Fin::engineSettings($db, $companyId)['require_sod'] === '1';
        $locked = (new PeriodService($db, $companyId))->isLocked($j['entry_date']);
        $editors = self::editors($db, $companyId, $id, (int)$j['created_by']);
        $sodBlocked = $sod && in_array($userId, $editors, true);
        $draft = in_array($j['status'], ['draft', 'prepared'], true);
        $can = [
            'edit' => $writer && $manual && $draft && !$locked,
            'delete' => $writer && $manual && $draft,
            'approve' => $writer && $manual && $draft && !$locked && !$sodBlocked && $cfg['rules']['approval'],
            'unapprove' => $writer && $manual && $j['status'] === 'approved',
            'post' => $writer && $manual && !$locked && ($j['status'] === 'approved' || ($draft && !$cfg['rules']['approval'] && !$sodBlocked)),
            'reverse' => $writer && $j['status'] === 'posted' && empty($j['reversed_by_journal_id']) && !self::isEngine($j),
            'duplicate' => $writer && count($lines) >= 2,
            'attach' => $writer,
        ];
        $why = null;
        if ($writer && $draft && !$manual) $why = 'This draft belongs to a document (' . ($j['ref_type'] ?: $j['module']) . '). It is changed through that document, not here.';
        elseif ($writer && $locked && $j['status'] !== 'posted') $why = 'The period of ' . $j['entry_date'] . ' is locked.';
        elseif ($writer && $draft && $sodBlocked && $cfg['rules']['approval']) $why = 'Someone other than the people who prepared or edited this journal must approve it.';
        elseif ($writer && $j['status'] === 'posted' && self::isEngine($j) && empty($j['reversed_by_journal_id'])) $why = 'A system journal is corrected through its own document (void the invoice, undo the bank match, and so on).';

        require_once __DIR__ . '/FinFiles.php';
        return [
            'journal' => [
                'id' => (int)$j['id'], 'date' => $j['entry_date'], 'reference' => (string)$j['reference'], 'description' => (string)$j['description'],
                'status' => $status, 'raw_status' => (string)$j['status'], 'source' => self::sourceOf($j), 'manual' => $manual,
                'module' => (string)$j['module'], 'ref_type' => (string)$j['ref_type'], 'ref_id' => $j['ref_id'] ? (int)$j['ref_id'] : null,
                'created_by' => Fin::personName($db, $companyId, $j['created_by']), 'created_at' => $j['created_at'],
                'approved_by' => Fin::personName($db, $companyId, $j['approved_by']), 'approved_at' => $j['approved_at'],
                'posted_by' => Fin::personName($db, $companyId, $j['posted_by']), 'posted_at' => $j['posted_at'],
                'reversed_by' => $j['reversed_by_journal_id'] ? (int)$j['reversed_by_journal_id'] : null,
                'reverses' => $j['reverses_journal_id'] ? (int)$j['reverses_journal_id'] : null,
                'locked' => $locked, 'debit' => $dc / 100, 'credit' => $cc / 100,
            ],
            'lines' => $lines,
            'link' => self::sourceLink($j),
            'trail' => self::trail($db, $companyId, $id),
            'files' => FinFiles::list($db, $companyId, 'journal', $id),
            'can' => $can,
            'why' => $why,
        ];
    }

    /** People who prepared or edited the journal (for segregation of duties). */
    public static function editors(PDO $db, int $companyId, int $id, int $createdBy): array
    {
        $ids = [$createdBy];
        $st = $db->prepare("SELECT DISTINCT user_id FROM audit_log WHERE company_id = ? AND entity_type = 'journal' AND entity_id = ?
                              AND action IN ('journal_created','journal_updated')");
        $st->execute([$companyId, $id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) $ids[] = (int)$u;
        // older edits were audited without an entity: match the id in the details
        $st = $db->prepare("SELECT DISTINCT user_id FROM audit_log WHERE company_id = ? AND action = 'journal_updated' AND entity_id IS NULL
                              AND JSON_VALID(details) AND JSON_EXTRACT(details, '$.journal_id') = ?");
        try { $st->execute([$companyId, $id]); foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) $ids[] = (int)$u; } catch (Throwable $e) { /* JSON functions missing */ }
        return array_values(array_unique(array_filter($ids)));
    }

    /** Where to open the document that posted this journal (new Finance routes or the owning module). */
    public static function sourceLink(array $j): ?array
    {
        $type = (string)($j['ref_type'] ?: $j['source_type']);
        $id = (int)($j['ref_type'] ? $j['ref_id'] : $j['source_id']);
        if ($id <= 0) return null;
        switch ($type) {
            case 'invoice': case 'invoice_write_off': return ['href' => '/qi/view.php?type=invoice&id=' . $id, 'label' => 'Open the invoice'];
            case 'credit_note': return ['href' => '/qi/view.php?type=credit_note&id=' . $id, 'label' => 'Open the credit note'];
            case 'ap_bill': return ['href' => '#payables?status=all&id=' . $id, 'label' => 'Open the bill'];
            case 'vendor_credit': return ['href' => '#payables?view=credits&credit=' . $id, 'label' => 'Open the supplier credit'];
            case 'ap_payment': return ['href' => '#payables?view=payments', 'label' => 'Supplier payments'];
            case 'pay_run': return ['href' => '/payroll/run_view.php?id=' . $id, 'label' => 'Open the payroll run'];
            case 'fa_disposal': return ['href' => '#assets?id=' . $id, 'label' => 'Open the asset'];
            case 'depreciation': return ['href' => '#assets?view=runs', 'label' => 'Depreciation runs'];
            case 'bank_tx': return ['href' => '#bank?status=all&txn=' . $id, 'label' => 'Open the bank line'];
            case 'vat_adjustment': case 'vat_period': return ['href' => '#vat?id=' . $id, 'label' => 'Open the VAT period'];
            case 'reversal': return ['href' => '#journals?id=' . $id, 'label' => 'Open the reversed journal'];
        }
        return null;
    }

    public static function trail(PDO $db, int $companyId, int $id): array
    {
        $st = $db->prepare("SELECT action, user_id, details, timestamp FROM audit_log
                             WHERE company_id = ? AND ((entity_type = 'journal' AND entity_id = ?)
                                OR (entity_id IS NULL AND action LIKE 'journal\\_%' AND details LIKE ?))
                          ORDER BY id DESC LIMIT 40");
        $st->execute([$companyId, $id, '%"journal_id":' . $id . '%']);
        $labels = ['journal_created' => 'Prepared', 'journal_updated' => 'Edited', 'journal_approved' => 'Approved', 'journal_unapproved' => 'Sent back to draft',
                   'journal_posted' => 'Posted', 'journal_reversed' => 'Reversed', 'journal_deleted' => 'Deleted', 'journal_attachment_added' => 'Document attached',
                   'journal_attachment_removed' => 'Document removed'];
        $out = [];
        $seen = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = $r['action'] . '|' . $r['timestamp'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $d = json_decode((string)$r['details'], true) ?: [];
            $out[] = ['what' => $labels[$r['action']] ?? str_replace('_', ' ', $r['action']), 'who' => Fin::personName($db, $companyId, $r['user_id']) ?: 'System',
                      'at' => $r['timestamp'], 'note' => isset($d['reason']) ? (string)$d['reason'] : ''];
        }
        return $out;
    }

    // -------------------------------------------------------------- writing

    /**
     * Create or update a draft. $in: id?, date, reference?, description, lines[{account_code, description, debit, credit,
     * tax_code_id?, project_id?, board_id?, item_id?}], post_now?, approve_now?
     * @return int journal id
     */
    public static function save(PDO $db, int $companyId, int $userId, string $role, array $in): int
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        $cfg = Fin::config($db, $companyId);
        $id = (int)($in['id'] ?? 0);
        $date = Fin::ymd($in['date'] ?? '');
        if (!$date) throw new FinUserError('Choose a valid date for the journal.');
        $ref = mb_substr(trim((string)($in['reference'] ?? '')), 0, 100);
        $memo = mb_substr(trim((string)($in['description'] ?? '')), 0, 255);
        if ($cfg['rules']['memo_required'] && $memo === '') throw new FinUserError('Say what this journal is for (your company asks for a description).');
        $lines = self::cleanLines($db, $companyId, (array)($in['lines'] ?? []));
        $periods = new PeriodService($db, $companyId);
        if ($periods->isLocked($date)) throw new FinUserError('The period of ' . $date . ' is locked. Choose a date after the lock date.', 409);

        $id = Fin::atomic($db, function () use ($db, $companyId, $userId, $id, $date, $ref, $memo, $lines, $periods) {
            if ($id > 0) {
                $st = $db->prepare("SELECT * FROM journal_entries WHERE id = ? AND company_id = ? FOR UPDATE");
                $st->execute([$id, $companyId]);
                $old = $st->fetch(PDO::FETCH_ASSOC);
                if (!$old) throw new FinUserError('That journal was not found.');
                if (!in_array($old['status'], ['draft', 'prepared'], true)) throw new FinUserError('Only drafts can be edited. Posted journals are corrected with a reversal.', 409);
                if (!self::isManual($old)) throw new FinUserError('This draft belongs to a document and cannot be edited here.', 409);
                if ($periods->isLocked($old['entry_date'])) throw new FinUserError('This draft is dated in a locked period and can no longer be edited.', 409);
                $newRef = $ref !== '' ? $ref : (string)$old['reference'];
                $db->prepare("UPDATE journal_entries SET entry_date = ?, reference = ?, description = ? WHERE id = ? AND company_id = ? AND status IN ('draft','prepared')")
                   ->execute([$date, $newRef, $memo, $id, $companyId]);
                $db->prepare("DELETE FROM journal_lines WHERE journal_id = ?")->execute([$id]);
                self::insertLines($db, $id, $lines);
                Fin::audit($db, $companyId, $userId, 'journal_updated', 'journal', $id, [
                    'journal_id' => $id, 'entry_date' => [$old['entry_date'], $date], 'reference' => [$old['reference'], $newRef],
                    'memo' => [$old['description'], $memo], 'total_cents' => array_sum(array_column($lines, 'debit_c')), 'line_count' => count($lines),
                ]);
                return $id;
            }
            if ($ref === '') $ref = (new Sequence($db, $companyId))->issue('JNL', ['prefix' => 'JNL-', 'pad' => 4, 'period_key' => 'ALL']);
            $db->prepare("INSERT INTO journal_entries (company_id, entry_date, reference, description, module, source_type, status, created_by, created_at)
                          VALUES (?, ?, ?, ?, 'manual', 'manual', 'draft', ?, NOW())")
               ->execute([$companyId, $date, $ref, $memo, $userId]);
            $id = (int)$db->lastInsertId();
            self::insertLines($db, $id, $lines);
            Fin::audit($db, $companyId, $userId, 'journal_created', 'journal', $id, [
                'journal_id' => $id, 'entry_date' => $date, 'reference' => $ref, 'total_cents' => array_sum(array_column($lines, 'debit_c')),
                'line_count' => count($lines), 'accounts' => array_values(array_unique(array_column($lines, 'account_code'))),
            ]);
            return $id;
        });
        return $id;
    }

    /** Validate and normalise journal lines (amounts to cents). */
    public static function cleanLines(PDO $db, int $companyId, array $raw): array
    {
        $lines = [];
        foreach ($raw as $i => $l) {
            if (!is_array($l)) continue;
            $code = trim((string)($l['account_code'] ?? ''));
            $d = Fin::cents($l['debit'] ?? '') ?? 0;
            $c = Fin::cents($l['credit'] ?? '') ?? 0;
            $desc = mb_substr(trim((string)($l['description'] ?? '')), 0, 255);
            if ($code === '' && $d === 0 && $c === 0 && $desc === '') continue; // empty row
            $n = count($lines) + 1;
            if ($code === '') throw new FinUserError("Line $n: choose an account.");
            if ($d < 0 || $c < 0) throw new FinUserError("Line $n: amounts cannot be negative — put the amount on the other side.");
            if ($d > 0 && $c > 0) throw new FinUserError("Line $n: a line is either a debit or a credit, not both.");
            if ($d === 0 && $c === 0) throw new FinUserError("Line $n: enter a debit or a credit amount.");
            if ($d > 99999999999 || $c > 99999999999) throw new FinUserError("Line $n: that amount is too large.");
            $lines[] = ['account_code' => $code, 'description' => $desc, 'debit_c' => $d, 'credit_c' => $c,
                        'tax_code_id' => (int)($l['tax_code_id'] ?? 0) ?: null, 'project_id' => (int)($l['project_id'] ?? 0) ?: null,
                        'board_id' => (int)($l['board_id'] ?? 0) ?: null, 'item_id' => (int)($l['item_id'] ?? 0) ?: null];
        }
        if (count($lines) < 2) throw new FinUserError('A journal needs at least two lines.');
        if (count($lines) > 500) throw new FinUserError('A journal can have up to 500 lines.');
        $dt = array_sum(array_column($lines, 'debit_c'));
        $ct = array_sum(array_column($lines, 'credit_c'));
        if ($dt !== $ct) {
            throw new FinUserError('The journal does not balance: debits ' . Fin::money($dt / 100) . ', credits ' . Fin::money($ct / 100)
                . ' (difference ' . Fin::money(abs($dt - $ct) / 100) . ').');
        }
        $codes = array_values(array_unique(array_column($lines, 'account_code')));
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $st = $db->prepare("SELECT account_code, is_active, allow_manual_journal FROM gl_accounts WHERE company_id = ? AND account_code IN ($ph)");
        $st->execute(array_merge([$companyId], $codes));
        $found = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) $found[$a['account_code']] = $a;
        foreach ($codes as $code) {
            if (!isset($found[$code])) throw new FinUserError("Account $code is not on your chart of accounts.");
            if (!(int)$found[$code]['is_active']) throw new FinUserError("Account $code is switched off. Switch it on in the chart of accounts first.");
            if (!(int)$found[$code]['allow_manual_journal']) throw new FinUserError("Account $code does not take manual journals (it is kept by its own documents, like receivables or payables).");
        }
        $taxIds = array_values(array_unique(array_filter(array_column($lines, 'tax_code_id'))));
        if ($taxIds) {
            $ph = implode(',', array_fill(0, count($taxIds), '?'));
            $st = $db->prepare("SELECT tax_code_id FROM gl_tax_codes WHERE company_id = ? AND tax_code_id IN ($ph)");
            $st->execute(array_merge([$companyId], $taxIds));
            $ok = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            foreach ($lines as $l) if ($l['tax_code_id'] && !in_array($l['tax_code_id'], $ok, true)) throw new FinUserError('Choose a VAT code from the list.');
        }
        // Project tags of this company only (an item only with its own board, a board on the tagged project).
        $pl = dirname(__DIR__, 2) . '/projects/lib/autoload.php';
        if (is_file($pl) && array_filter(array_merge(array_column($lines, 'project_id'), array_column($lines, 'board_id')))) {
            require_once $pl;
            foreach ($lines as $i => $l) {
                $p = $l['project_id']; $b = $l['board_id']; $it = $l['item_id'];
                if ($p && !ProjectLink::belongs($db, $companyId, $p)) $p = null;
                if ($b && !ProjectLink::boardBelongs($db, $companyId, $b)) $b = null;
                if ($it && (!$b || !ProjectLink::boardBelongs($db, $companyId, $b, $it))) $it = null;
                if ($b) {
                    $bp = ProjectLink::projectOfBoard($db, $companyId, $b) ?: null;
                    if ($p && $bp !== $p) { $b = null; $it = null; }
                    elseif (!$p && $bp) $p = $bp;
                }
                $lines[$i]['project_id'] = $p; $lines[$i]['board_id'] = $b; $lines[$i]['item_id'] = $it;
            }
        } else {
            foreach ($lines as $i => $l) { $lines[$i]['project_id'] = null; $lines[$i]['board_id'] = null; $lines[$i]['item_id'] = null; }
        }
        return $lines;
    }

    private static function insertLines(PDO $db, int $journalId, array $lines): void
    {
        $st = $db->prepare("INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, tax_code_id, project_id, board_id, item_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($lines as $l) {
            $st->execute([$journalId, $l['account_code'], $l['description'], Fin::rand($l['debit_c']), Fin::rand($l['credit_c']),
                          $l['tax_code_id'], $l['project_id'], $l['board_id'], $l['item_id']]);
        }
    }

    /** Lock a manual journal row for a status change and check it may move. */
    private static function lockRow(PDO $db, int $companyId, int $id): array
    {
        $st = $db->prepare("SELECT * FROM journal_entries WHERE id = ? AND company_id = ? FOR UPDATE");
        $st->execute([$id, $companyId]);
        $j = $st->fetch(PDO::FETCH_ASSOC);
        if (!$j) throw new FinUserError('That journal was not found.');
        if (!self::isManual($j)) throw new FinUserError('This journal belongs to a document and is managed there.', 409);
        return $j;
    }

    public static function approve(PDO $db, int $companyId, int $userId, string $role, int $id): void
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        Fin::atomic($db, function () use ($db, $companyId, $userId, $id) {
            $j = self::lockRow($db, $companyId, $id);
            if (!in_array($j['status'], ['draft', 'prepared'], true)) throw new FinUserError('Only drafts can be approved.', 409);
            self::assertApprovable($db, $companyId, $userId, $j);
            $db->prepare("UPDATE journal_entries SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND company_id = ?")
               ->execute([$userId, $id, $companyId]);
            Fin::audit($db, $companyId, $userId, 'journal_approved', 'journal', $id, ['journal_id' => $id]);
        });
    }

    private static function assertApprovable(PDO $db, int $companyId, int $userId, array $j): void
    {
        if ((new PeriodService($db, $companyId))->isLocked($j['entry_date'])) throw new FinUserError('The period of ' . $j['entry_date'] . ' is locked.', 409);
        if (Fin::engineSettings($db, $companyId)['require_sod'] === '1' && in_array($userId, self::editors($db, $companyId, (int)$j['id'], (int)$j['created_by']), true)) {
            throw new FinForbidden('Segregation of duties: someone other than the people who prepared or edited this journal must approve it.');
        }
        self::checkStoredLines($db, $companyId, (int)$j['id']);
    }

    public static function unapprove(PDO $db, int $companyId, int $userId, string $role, int $id): void
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        Fin::atomic($db, function () use ($db, $companyId, $userId, $id) {
            $j = self::lockRow($db, $companyId, $id);
            if ($j['status'] !== 'approved') throw new FinUserError('Only approved journals can go back to draft.', 409);
            $db->prepare("UPDATE journal_entries SET status = 'draft', approved_by = NULL, approved_at = NULL WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
            Fin::audit($db, $companyId, $userId, 'journal_unapproved', 'journal', $id, ['journal_id' => $id]);
        });
    }

    /** Post an approved journal (or a draft directly when the company has no approval step). */
    public static function post(PDO $db, int $companyId, int $userId, string $role, int $id): void
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        $cfg = Fin::config($db, $companyId);
        Fin::atomic($db, function () use ($db, $companyId, $userId, $id, $cfg) {
            $j = self::lockRow($db, $companyId, $id);
            $draft = in_array($j['status'], ['draft', 'prepared'], true);
            if ($j['status'] === 'posted') throw new FinUserError('This journal is already posted.', 409);
            if ($draft && $cfg['rules']['approval']) throw new FinUserError('Approve the journal first — your company posts journals only after approval.', 409);
            if (!$draft && $j['status'] !== 'approved') throw new FinUserError('Only approved journals can be posted.', 409);
            if ($draft) self::assertApprovable($db, $companyId, $userId, $j);
            if ((new PeriodService($db, $companyId))->isLocked($j['entry_date'])) throw new FinUserError('The period of ' . $j['entry_date'] . ' is locked.', 409);
            $total = self::checkStoredLines($db, $companyId, $id);
            if ($cfg['rules']['attachments_required']) {
                require_once __DIR__ . '/FinFiles.php';
                if (!FinFiles::list($db, $companyId, 'journal', $id)) throw new FinUserError('Attach the supporting document before posting (your company asks for one).', 409);
            }
            $set = $draft ? "status = 'posted', approved_by = ?, approved_at = NOW(), posted_by = ?, posted_at = NOW()" : "status = 'posted', posted_by = ?, posted_at = NOW()";
            $args = $draft ? [$userId, $userId, $id, $companyId] : [$userId, $id, $companyId];
            $st = $db->prepare("UPDATE journal_entries SET $set WHERE id = ? AND company_id = ? AND status <> 'posted'");
            $st->execute($args);
            if ($st->rowCount() !== 1) throw new FinUserError('The journal changed while you were posting it. Reload and try again.', 409);
            Fin::audit($db, $companyId, $userId, 'journal_posted', 'journal', $id, ['journal_id' => $id, 'total_cents' => $total, 'reference' => $j['reference'], 'direct' => $draft]);
        });
    }

    /** Re-check the stored lines of a manual journal before it moves forward. @return int total debits in cents */
    private static function checkStoredLines(PDO $db, int $companyId, int $id): int
    {
        $st = $db->prepare("SELECT account_code, debit, credit, tax_code_id FROM journal_lines WHERE journal_id = ?");
        $st->execute([$id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $lines = array_map(fn($r) => ['account_code' => $r['account_code'], 'debit' => $r['debit'], 'credit' => $r['credit'], 'tax_code_id' => $r['tax_code_id']], $rows);
        $clean = self::cleanLines($db, $companyId, $lines);
        return array_sum(array_column($clean, 'debit_c'));
    }

    /** Reverse a posted journal on a date in an open period (default: its own date when open, else today). */
    public static function reverse(PDO $db, int $companyId, int $userId, string $role, int $id, string $reason, ?string $date = null): int
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        $reason = mb_substr(trim($reason), 0, 255);
        if ($reason === '') throw new FinUserError('Give a reason for the reversal (it stays on the audit trail).');
        if ($date !== null && $date !== '' && !Fin::ymd($date)) throw new FinUserError('Choose a valid reversal date.');
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $id, $reason, $date) {
            $st = $db->prepare("SELECT * FROM journal_entries WHERE id = ? AND company_id = ? FOR UPDATE");
            $st->execute([$id, $companyId]);
            $j = $st->fetch(PDO::FETCH_ASSOC);
            if (!$j) throw new FinUserError('That journal was not found.');
            if ($j['status'] !== 'posted') throw new FinUserError('Only posted journals can be reversed. A draft can simply be deleted.', 409);
            if (!empty($j['reversed_by_journal_id'])) throw new FinUserError('This journal was already reversed (by #' . $j['reversed_by_journal_id'] . ').', 409);
            if (self::isEngine($j)) throw new FinUserError('This is a system journal (' . ($j['ref_type'] ?: $j['module']) . '). Correct it through its own document.', 409);
            $periods = new PeriodService($db, $companyId);
            $on = $date ?: ($periods->isLocked($j['entry_date']) ? date('Y-m-d') : $j['entry_date']);
            if ($periods->isLocked($on)) throw new FinUserError('The reversal date ' . $on . ' is in a locked period.', 409);
            $rev = (new ReversalService($db, $companyId))->reverseJournal($id, $userId, $reason, $on);
            (new InventoryService($db, $companyId))->reverseMovementsForJournal($id, $rev, $on);
            return $rev;
        });
    }

    public static function delete(PDO $db, int $companyId, int $userId, string $role, int $id): void
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        Fin::atomic($db, function () use ($db, $companyId, $userId, $id) {
            $j = self::lockRow($db, $companyId, $id);
            if (!in_array($j['status'], ['draft', 'prepared'], true)) throw new FinUserError('Only drafts can be deleted. A posted journal is corrected with a reversal.', 409);
            $st = $db->prepare("SELECT COALESCE(SUM(ROUND(debit * 100)), 0) FROM journal_lines WHERE journal_id = ?");
            $st->execute([$id]);
            $total = (int)$st->fetchColumn();
            $db->prepare("DELETE FROM journal_lines WHERE journal_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM journal_entries WHERE id = ? AND company_id = ? AND status IN ('draft','prepared')")->execute([$id, $companyId]);
            require_once __DIR__ . '/FinFiles.php';
            FinFiles::detachAll($db, $companyId, $userId, 'journal', $id);
            Fin::audit($db, $companyId, $userId, 'journal_deleted', 'journal', $id, ['journal_id' => $id, 'reference' => $j['reference'], 'entry_date' => $j['entry_date'],
                       'memo' => $j['description'], 'total_cents' => $total]);
        });
    }

    /** Lines of any journal as a starting point for a new draft (system tags dropped). */
    public static function duplicateLines(PDO $db, int $companyId, int $id, bool $swap = false): array
    {
        $st = $db->prepare("SELECT je.description, je.reference FROM journal_entries je WHERE je.id = ? AND je.company_id = ?");
        $st->execute([$id, $companyId]);
        $j = $st->fetch(PDO::FETCH_ASSOC);
        if (!$j) throw new FinUserError('That journal was not found.');
        $st = $db->prepare("SELECT account_code, description, debit, credit, tax_code_id, project_id, board_id, item_id FROM journal_lines WHERE journal_id = ? ORDER BY id");
        $st->execute([$id]);
        $lines = array_map(fn($l) => [
            'account_code' => $l['account_code'], 'description' => $l['description'],
            'debit' => round((float)($swap ? $l['credit'] : $l['debit']), 2), 'credit' => round((float)($swap ? $l['debit'] : $l['credit']), 2),
            'tax_code_id' => $l['tax_code_id'] ? (int)$l['tax_code_id'] : null, 'project_id' => $l['project_id'] ? (int)$l['project_id'] : null,
            'board_id' => $l['board_id'] ? (int)$l['board_id'] : null, 'item_id' => $l['item_id'] ? (int)$l['item_id'] : null,
        ], $st->fetchAll(PDO::FETCH_ASSOC));
        return ['description' => (string)$j['description'], 'lines' => $lines];
    }
}
