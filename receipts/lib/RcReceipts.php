<?php
// /receipts/lib/RcReceipts.php — receipts as a list: filters and counts, the
// overview's figures, and what can be done to a receipt itself (a note, set
// aside, bring back, delete, export). Members see the receipts they sent in;
// admins, bookkeepers and viewers see the company's.

final class RcReceipts
{
    public const VIEWS = ['todo', 'reading', 'review', 'draft', 'blocked', 'done', 'aside', 'all'];
    private const PER = 40;

    /** $f: view, q, supplier, from, to, mine, page, sort (new|old|amount) */
    public static function list(PDO $db, int $companyId, int $userId, string $role, array $f): array
    {
        $args = [$companyId];
        $where = ['rf.company_id = ?', RcKit::scopeSql($role, $userId, $args)];
        if (!empty($f['mine'])) { $where[] = 'rf.uploaded_by = ?'; $args[] = $userId; }
        if ((int)($f['supplier'] ?? 0)) { $where[] = 'b.supplier_id = ?'; $args[] = (int)$f['supplier']; }
        if ($d = RcKit::ymd($f['from'] ?? '')) { $where[] = 'COALESCE(b.issue_date, ro.invoice_date, DATE(rf.uploaded_at)) >= ?'; $args[] = $d; }
        if ($d = RcKit::ymd($f['to'] ?? '')) { $where[] = 'COALESCE(b.issue_date, ro.invoice_date, DATE(rf.uploaded_at)) <= ?'; $args[] = $d; }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . RcKit::likeEscape($q) . '%';
            $c = preg_match('/^[R\s\d,.]+$/i', $q) ? RcKit::cents($q) : null;
            $where[] = '(ro.vendor_name LIKE ? OR ro.invoice_number LIKE ? OR b.vendor_invoice_number LIKE ? OR ca.name LIKE ? OR rf.original_name LIKE ? OR rf.note LIKE ?'
                     . ($c ? ' OR ROUND(COALESCE(b.total, ro.total) * 100) = ?' : '') . ')';
            array_push($args, $like, $like, $like, $like, $like, $like);
            if ($c) $args[] = abs($c);
        }
        $state = RcKit::statusSql();
        $blocked = "EXISTS (SELECT 1 FROM receipt_policy_log pl WHERE pl.company_id = rf.company_id AND pl.file_id = rf.file_id AND pl.severity = 'error' AND pl.override_by IS NULL)";
        $from = "FROM receipt_file rf LEFT JOIN receipt_ocr ro ON ro.file_id = rf.file_id
                 LEFT JOIN ap_bills b ON b.id = rf.bill_id AND b.company_id = rf.company_id
                 LEFT JOIN crm_accounts ca ON ca.id = b.supplier_id AND ca.company_id = rf.company_id";
        $base = implode(' AND ', $where);
        $st = $db->prepare("SELECT $state s, ($blocked) bl, COUNT(*) n $from WHERE $base GROUP BY s, bl");
        $st->execute($args);
        $counts = array_fill_keys(self::VIEWS, 0);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = (int)$r['n'];
            $counts['all'] += $n;
            if (isset($counts[$r['s']])) $counts[$r['s']] += $n;
            if (in_array($r['s'], ['reading', 'review', 'draft'], true)) $counts['todo'] += $n;
            if (in_array($r['s'], ['posted', 'paid'], true)) $counts['done'] += $n;
            if ((int)$r['bl'] && in_array($r['s'], ['review', 'draft'], true)) $counts['blocked'] += $n;
        }
        $view = in_array($f['view'] ?? '', self::VIEWS, true) ? $f['view'] : 'todo';
        $cond = [
            'todo' => "$state IN ('reading','review','draft')", 'reading' => "$state = 'reading'", 'review' => "$state = 'review'", 'draft' => "$state = 'draft'",
            'blocked' => "$state IN ('review','draft') AND $blocked", 'done' => "$state IN ('posted','paid')", 'aside' => "$state = 'aside'", 'all' => '1 = 1',
        ][$view];
        $order = ['new' => 'rf.uploaded_at DESC, rf.file_id DESC', 'old' => 'rf.uploaded_at ASC, rf.file_id ASC',
                  'amount' => 'COALESCE(b.total, ro.total, 0) DESC, rf.file_id DESC', 'date' => 'COALESCE(b.issue_date, ro.invoice_date) DESC, rf.file_id DESC'][$f['sort'] ?? ''] ?? ($view === 'todo' ? 'rf.uploaded_at ASC, rf.file_id ASC' : 'rf.uploaded_at DESC, rf.file_id DESC');
        $page = max(1, (int)($f['page'] ?? 1));
        $st = $db->prepare("SELECT COUNT(*), COALESCE(SUM(COALESCE(b.total, ro.total, 0)), 0) $from WHERE $base AND $cond");
        $st->execute($args);
        [$total, $sum] = $st->fetch(PDO::FETCH_NUM);
        $st = $db->prepare("SELECT rf.file_id, rf.company_id, rf.path, rf.original_name, rf.mime, rf.size_bytes, rf.uploaded_by, rf.uploaded_at, rf.ocr_status, rf.note, rf.thumb_path,
                                   rf.read_engine, rf.read_error, rf.dismissed_at, rf.dismissed_by, rf.dismiss_reason, rf.invoice_id, rf.paid_method,
                                   ro.vendor_name, ro.invoice_number, ro.invoice_date, ro.total, ro.confidence_score,
                                   b.id b_id, b.status b_status, b.total b_total, b.journal_id b_journal, b.vendor_invoice_number b_number, b.issue_date b_issued, ca.name supplier,
                                   $state state, ($blocked) blocked
                              $from WHERE $base AND $cond ORDER BY $order LIMIT " . self::PER . " OFFSET " . (($page - 1) * self::PER));
        $st->execute($args);
        $rows = array_map(fn($r) => self::shape($db, $r), $st->fetchAll(PDO::FETCH_ASSOC));
        return ['rows' => $rows, 'total' => (int)$total, 'sum' => round((float)$sum, 2), 'page' => $page, 'per' => self::PER, 'counts' => $counts, 'view' => $view];
    }

    private static function shape(PDO $db, array $r): array
    {
        $base = RcBills::shapeRow($db, $r);
        $amount = $r['b_id'] ? (float)$r['b_total'] : ($r['total'] !== null ? (float)$r['total'] : null);
        return $base + [
            'supplier' => (string)($r['supplier'] ?? ''), 'vendor' => (string)($r['vendor_name'] ?? ''),
            'number' => (string)($r['b_number'] ?? $r['invoice_number'] ?? ''), 'date' => $r['b_issued'] ?? $r['invoice_date'],
            'amount' => $amount !== null ? round($amount, 2) : null, 'blocked' => (bool)(int)($r['blocked'] ?? 0),
            'read' => ['state' => (string)$r['ocr_status'], 'engine' => (string)($r['read_engine'] ?? ''), 'error' => (string)($r['read_error'] ?? ''),
                       'confidence' => $r['confidence_score'] !== null ? (float)$r['confidence_score'] : null],
            'paid_on_spot' => (string)($r['paid_method'] ?? '') !== '',
        ];
    }

    /** The sidebar's numbers: to do, held by a check, in the books, set aside, and this month's spend from receipts. */
    public static function navCounts(PDO $db, int $companyId, int $userId, string $role): array
    {
        $args = [$companyId];
        $scope = RcKit::scopeSql($role, $userId, $args);
        $state = RcKit::statusSql();
        $st = $db->prepare("SELECT $state s, COUNT(*) n,
                                   SUM(EXISTS (SELECT 1 FROM receipt_policy_log pl WHERE pl.company_id = rf.company_id AND pl.file_id = rf.file_id AND pl.severity = 'error' AND pl.override_by IS NULL)) bl,
                                   SUM(CASE WHEN b.status IN ('posted','paid') AND b.issue_date >= ? THEN b.total ELSE 0 END) m
                              FROM receipt_file rf LEFT JOIN ap_bills b ON b.id = rf.bill_id AND b.company_id = rf.company_id
                             WHERE rf.company_id = ? AND $scope GROUP BY s");
        $st->execute(array_merge([date('Y-m-01')], $args));
        $out = ['todo' => 0, 'blocked' => 0, 'done' => 0, 'aside' => 0, 'month' => 0.0, 'all' => 0];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = (int)$r['n'];
            $out['all'] += $n;
            $out['month'] += (float)$r['m'];
            if (in_array($r['s'], ['reading', 'review', 'draft'], true)) $out['todo'] += $n;
            if (in_array($r['s'], ['review', 'draft'], true)) $out['blocked'] += (int)$r['bl'];
            if (in_array($r['s'], ['posted', 'paid'], true)) $out['done'] += $n;
            if ($r['s'] === 'aside') $out['aside'] += $n;
        }
        $out['month'] = round($out['month'], 2);
        return $out;
    }

    // ------------------------------------------------------------ overview

    public static function overview(PDO $db, int $companyId, int $userId, string $role): array
    {
        $args = [$companyId];
        $scope = RcKit::scopeSql($role, $userId, $args);
        $state = RcKit::statusSql();
        $st = $db->prepare("SELECT $state s, COUNT(*) n, COALESCE(SUM(COALESCE(b.total, ro.total, 0)), 0) amt, MIN(rf.uploaded_at) oldest
                              FROM receipt_file rf LEFT JOIN receipt_ocr ro ON ro.file_id = rf.file_id LEFT JOIN ap_bills b ON b.id = rf.bill_id AND b.company_id = rf.company_id
                             WHERE rf.company_id = ? AND $scope GROUP BY s");
        $st->execute($args);
        $by = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $by[$r['s']] = ['n' => (int)$r['n'], 'amount' => round((float)$r['amt'], 2), 'oldest' => $r['oldest']];
        $get = fn($k) => $by[$k] ?? ['n' => 0, 'amount' => 0.0, 'oldest' => null];
        $todo = $get('reading')['n'] + $get('review')['n'] + $get('draft')['n'];
        $oldest = array_filter([$get('reading')['oldest'], $get('review')['oldest'], $get('draft')['oldest']]);

        // Spend from receipts, by month (bills that came from a receipt and are in the books)
        $st = $db->prepare("SELECT DATE_FORMAT(b.issue_date, '%Y-%m') ym, SUM(b.total) t, COUNT(*) n FROM receipt_file rf JOIN ap_bills b ON b.id = rf.bill_id AND b.company_id = rf.company_id
                             WHERE rf.company_id = ? AND $scope AND b.status IN ('posted','paid') AND b.issue_date >= ? GROUP BY ym");
        $st->execute(array_merge($args, [date('Y-m-01', strtotime('-11 months'))]));
        $m = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $m[$r['ym']] = ['t' => round((float)$r['t'], 2), 'n' => (int)$r['n']];
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $ym = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
            $months[] = ['ym' => $ym, 'label' => date('M', strtotime($ym . '-01')), 'total' => $m[$ym]['t'] ?? 0.0, 'n' => $m[$ym]['n'] ?? 0];
        }
        $thisMonth = $months[11]['total'];
        $lastMonth = $months[10]['total'];

        // Where it went: suppliers and accounts, last 90 days
        $since = date('Y-m-d', strtotime('-90 days'));
        $st = $db->prepare("SELECT ca.id, ca.name, SUM(b.total) t, COUNT(*) n FROM receipt_file rf JOIN ap_bills b ON b.id = rf.bill_id AND b.company_id = rf.company_id
                              JOIN crm_accounts ca ON ca.id = b.supplier_id AND ca.company_id = b.company_id
                             WHERE rf.company_id = ? AND $scope AND b.status IN ('posted','paid') AND b.issue_date >= ? GROUP BY ca.id, ca.name ORDER BY t DESC LIMIT 6");
        $st->execute(array_merge($args, [$since]));
        $suppliers = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'total' => round((float)$r['t'], 2), 'n' => (int)$r['n']], $st->fetchAll(PDO::FETCH_ASSOC));
        $st = $db->prepare("SELECT ga.account_code, ga.account_name, SUM(bl.quantity * bl.unit_price - bl.discount) t FROM receipt_file rf
                              JOIN ap_bills b ON b.id = rf.bill_id AND b.company_id = rf.company_id JOIN ap_bill_lines bl ON bl.bill_id = b.id
                              LEFT JOIN gl_accounts ga ON ga.account_id = bl.gl_account_id AND ga.company_id = b.company_id
                             WHERE rf.company_id = ? AND $scope AND b.status IN ('posted','paid') AND b.issue_date >= ? GROUP BY ga.account_code, ga.account_name ORDER BY t DESC LIMIT 6");
        $st->execute(array_merge($args, [$since]));
        $accounts = array_map(fn($r) => ['name' => $r['account_name'] ? $r['account_code'] . ' · ' . $r['account_name'] : 'Default expense account', 'total' => round((float)$r['t'], 2)], $st->fetchAll(PDO::FETCH_ASSOC));

        // Project costs from receipts this month (projects the person can open)
        $projects = [];
        try {
            [$vis] = ProjectLink::repo($db, $companyId, $userId, $role)->visibleSql('p');
            $st = $db->prepare("SELECT p.project_id, p.project_number, p.name, SUM(bl.quantity * bl.unit_price - bl.discount) t FROM receipt_file rf
                                  JOIN ap_bills b ON b.id = rf.bill_id AND b.company_id = rf.company_id JOIN ap_bill_lines bl ON bl.bill_id = b.id
                                  JOIN project_boards pb ON pb.board_id = bl.project_board_id AND pb.company_id = b.company_id
                                  JOIN projects p ON p.project_id = pb.project_id AND p.company_id = b.company_id
                                 WHERE rf.company_id = ? AND $scope AND b.status IN ('posted','paid') AND b.issue_date >= ? AND $vis
                                 GROUP BY p.project_id, p.project_number, p.name ORDER BY t DESC LIMIT 5");
            $st->execute(array_merge($args, [$since]));
            $projects = array_map(fn($r) => ['id' => (int)$r['project_id'], 'name' => ((string)$r['project_number'] !== '' ? $r['project_number'] . ' · ' : '') . $r['name'],
                                             'total' => round((float)$r['t'], 2), 'url' => ProjectLink::projectUrl((int)$r['project_id'])], $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            error_log('[receipts] overview projects: ' . $e->getMessage());
        }

        // What needs a person
        $attn = [];
        $one = function (string $sql, array $a) use ($db) { $s = $db->prepare($sql); $s->execute($a); return (int)$s->fetchColumn(); };
        $base = "FROM receipt_file rf LEFT JOIN ap_bills b ON b.id = rf.bill_id AND b.company_id = rf.company_id WHERE rf.company_id = ? AND $scope";
        if ($n = $one("SELECT COUNT(*) $base AND rf.dismissed_at IS NULL AND b.id IS NULL AND rf.ocr_status = 'failed'", $args)) {
            $attn[] = ['icon' => 'i-eye', 'title' => $n . ' ' . ($n === 1 ? 'slip' : 'slips') . ' could not be read', 'text' => 'Type the details in, or try reading again.', 'href' => '/receipts/inbox.php?view=review'];
        }
        if ($n = $one("SELECT COUNT(*) $base AND rf.dismissed_at IS NULL AND (b.id IS NULL OR b.status IN ('draft','review','approved'))
                       AND EXISTS (SELECT 1 FROM receipt_policy_log pl WHERE pl.company_id = rf.company_id AND pl.file_id = rf.file_id AND pl.severity = 'error' AND pl.override_by IS NULL)", $args)) {
            $attn[] = ['icon' => 'i-alert', 'title' => $n . ' ' . ($n === 1 ? 'bill is' : 'bills are') . ' held by a check', 'text' => 'A missing VAT number, compliance document or purchase order.', 'href' => '/receipts/inbox.php?view=blocked'];
        }
        if ($n = $one("SELECT COUNT(*) $base AND rf.dismissed_at IS NULL AND (b.id IS NULL OR b.status IN ('draft','review','approved')) AND rf.uploaded_at < ?", array_merge($args, [date('Y-m-d H:i:s', strtotime('-14 days'))]))) {
            $attn[] = ['icon' => 'i-clock', 'title' => $n . ' ' . ($n === 1 ? 'receipt has' : 'receipts have') . ' waited over two weeks', 'text' => 'Input VAT is claimed in the period of the invoice — older slips are easy to miss.', 'href' => '/receipts/inbox.php?view=todo&sort=old'];
        }
        if ($n = $one("SELECT COUNT(*) $base AND rf.dismissed_at IS NULL AND b.status IN ('draft','review','approved')", $args)) {
            $attn[] = ['icon' => 'if-post', 'title' => $n . ' draft ' . ($n === 1 ? 'bill is' : 'bills are') . ' not posted', 'text' => 'They are not in the books yet.', 'href' => '/receipts/inbox.php?view=draft'];
        }

        $recent = self::rows($db, $args, "rf.company_id = ? AND $scope", 'rf.uploaded_at DESC, rf.file_id DESC', 8);
        $queue = self::rows($db, $args, "rf.company_id = ? AND $scope AND rf.dismissed_at IS NULL AND (b.id IS NULL OR b.status IN ('draft','review','approved'))", 'rf.uploaded_at ASC, rf.file_id ASC', 6);

        return [
            'todo' => $todo, 'reading' => $get('reading')['n'], 'review' => $get('review')['n'], 'draft' => $get('draft')['n'],
            'done' => $get('posted')['n'] + $get('paid')['n'], 'aside' => $get('aside')['n'], 'total' => array_sum(array_column($by, 'n')),
            'todo_amount' => round($get('review')['amount'] + $get('draft')['amount'], 2), 'oldest' => $oldest ? min($oldest) : null,
            'months' => $months, 'this_month' => $thisMonth, 'last_month' => $lastMonth,
            'suppliers' => $suppliers, 'accounts' => $accounts, 'projects' => $projects, 'attention' => $attn, 'recent' => $recent, 'queue' => $queue,
        ];
    }

    /** Receipt rows for the overview's short lists. */
    private static function rows(PDO $db, array $args, string $where, string $order, int $limit): array
    {
        $state = RcKit::statusSql();
        $st = $db->prepare("SELECT rf.file_id, rf.company_id, rf.path, rf.original_name, rf.mime, rf.size_bytes, rf.uploaded_by, rf.uploaded_at, rf.ocr_status, rf.note, rf.thumb_path,
                                   rf.read_engine, rf.read_error, rf.dismissed_at, rf.dismissed_by, rf.dismiss_reason, rf.invoice_id, rf.paid_method,
                                   ro.vendor_name, ro.invoice_number, ro.invoice_date, ro.total, ro.confidence_score,
                                   b.id b_id, b.status b_status, b.total b_total, b.journal_id b_journal, b.vendor_invoice_number b_number, b.issue_date b_issued, ca.name supplier,
                                   $state state, 0 blocked
                              FROM receipt_file rf LEFT JOIN receipt_ocr ro ON ro.file_id = rf.file_id
                              LEFT JOIN ap_bills b ON b.id = rf.bill_id AND b.company_id = rf.company_id
                              LEFT JOIN crm_accounts ca ON ca.id = b.supplier_id AND ca.company_id = rf.company_id
                             WHERE $where ORDER BY $order LIMIT $limit");
        $st->execute($args);
        return array_map(fn($r) => self::shape($db, $r), $st->fetchAll(PDO::FETCH_ASSOC));
    }

    // ------------------------------------------------------------- actions

    public static function setNote(PDO $db, int $companyId, int $userId, string $role, int $fileId, string $note): void
    {
        $row = RcBills::need($db, $companyId, $userId, $role, $fileId);
        if (!RcKit::canCapture($role) && (int)$row['uploaded_by'] !== $userId) throw new RcForbidden('Only the person who sent it in, or a bookkeeper, can change the note.');
        $note = mb_substr(trim($note), 0, 500);
        $db->prepare("UPDATE receipt_file SET note = ? WHERE file_id = ? AND company_id = ?")->execute([$note !== '' ? $note : null, $fileId, $companyId]);
    }

    /**
     * Run $one on each receipt of a batch. One that cannot be done (in the
     * books, not theirs) is kept and the rest go ahead; when none could be,
     * the first reason is the error. @return int how many were done
     */
    private static function each(array $ids, callable $one, ?array &$kept = null): int
    {
        $done = 0;
        $kept = [];
        $first = null;
        foreach (self::ids($ids) as $id) {
            try {
                if ($one($id)) $done++;
            } catch (RcProblem | RcForbidden | FinUserError $e) {
                $kept[] = ['id' => $id, 'why' => $e->getMessage()];
                $first = $first ?? $e;
            }
        }
        if (!$done && $first) throw $first;
        return $done;
    }

    /** Set receipts aside (not bills). A draft bill on one is deleted with it. @return int how many */
    public static function aside(PDO $db, int $companyId, int $userId, string $role, array $ids, string $reason, string $note = '', ?array &$kept = null): int
    {
        if (!RcKit::canCapture($role)) throw new RcForbidden('Only admins and bookkeepers can set receipts aside.');
        if (!isset(RcKit::ASIDE_REASONS[$reason])) throw new RcProblem('Choose why it is set aside.', 422);
        $text = RcKit::ASIDE_REASONS[$reason] . (trim($note) !== '' ? ' — ' . mb_substr(trim($note), 0, 180) : '');
        return self::each($ids, function (int $id) use ($db, $companyId, $userId, $role, $text): bool {
            $row = RcBills::need($db, $companyId, $userId, $role, $id);
            if ($row['dismissed_at']) return false;
            if (in_array($row['b_status'], ['posted', 'paid'], true)) throw new RcProblem('Receipt #' . $id . ' is in the books and cannot be set aside.', 409);
            if ($row['b_id']) RcBills::deleteDraft($db, $companyId, $userId, $role, $id);
            $db->prepare("UPDATE receipt_file SET dismissed_at = NOW(), dismissed_by = ?, dismiss_reason = ? WHERE file_id = ? AND company_id = ?")->execute([$userId, $text, $id, $companyId]);
            RcKit::audit($db, $companyId, $userId, 'receipt_set_aside', $id, ['reason' => $text]);
            RcKit::notify($db, $companyId, (int)$row['uploaded_by'], $userId, 'receipt_set_aside', 'A receipt you sent in was set aside', $text, '/receipts/review.php?id=' . $id, true);
            return true;
        }, $kept);
    }

    public static function restore(PDO $db, int $companyId, int $userId, string $role, array $ids, ?array &$kept = null): int
    {
        if (!RcKit::canCapture($role)) throw new RcForbidden('Only admins and bookkeepers can bring receipts back.');
        return self::each($ids, function (int $id) use ($db, $companyId, $userId, $role): bool {
            RcBills::need($db, $companyId, $userId, $role, $id);
            $st = $db->prepare("UPDATE receipt_file SET dismissed_at = NULL, dismissed_by = NULL, dismiss_reason = NULL WHERE file_id = ? AND company_id = ? AND dismissed_at IS NOT NULL");
            $st->execute([$id, $companyId]);
            if (!$st->rowCount()) return false;
            RcKit::audit($db, $companyId, $userId, 'receipt_restored', $id);
            return true;
        }, $kept);
    }

    /**
     * Delete receipts and their files. A receipt behind a bill in the books
     * stays (it is the source document). Members may delete their own until
     * a bill is made from it.
     */
    public static function delete(PDO $db, int $companyId, int $userId, string $role, array $ids, ?array &$kept = null): int
    {
        return self::each($ids, function (int $id) use ($db, $companyId, $userId, $role): bool {
            $row = RcBills::need($db, $companyId, $userId, $role, $id);
            if (in_array($row['b_status'], ['posted', 'paid'], true)) throw new RcProblem('Receipt #' . $id . ' is behind a bill in the books, so it is kept.', 409);
            $own = (int)$row['uploaded_by'] === $userId && !$row['b_id'];
            if (!RcKit::canCapture($role) && !$own) throw new RcForbidden('You can delete the receipts you sent in until a bill is made from them.');
            if ($row['b_id']) RcBills::deleteDraft($db, $companyId, $userId, $role, $id);
            Fin::atomic($db, function () use ($db, $companyId, $id) {
                $db->prepare("DELETE FROM receipt_policy_log WHERE company_id = ? AND file_id = ?")->execute([$companyId, $id]);
                $db->prepare("DELETE FROM receipt_ocr WHERE file_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM receipt_file WHERE file_id = ? AND company_id = ?")->execute([$id, $companyId]);
            });
            RcFiles::unlinkFiles($row);
            RcKit::audit($db, $companyId, $userId, 'receipt_deleted', $id, ['name' => $row['original_name'], 'sha1' => $row['sha1']]);
            return true;
        }, $kept);
    }

    /** CSV of the list as filtered (formulas defused). */
    public static function csv(PDO $db, int $companyId, int $userId, string $role, array $f): void
    {
        $rows = [];
        for ($page = 1; $page <= 50; $page++) {
            $res = self::list($db, $companyId, $userId, $role, ['page' => $page] + $f);
            $rows = array_merge($rows, $res['rows']);
            if (count($res['rows']) < $res['per']) break;
        }
        $cell = function ($v) {
            $v = (string)$v;
            if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) $v = "'" . $v;
            return '"' . str_replace('"', '""', $v) . '"';
        };
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="receipts-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-store');
        echo "\xEF\xBB\xBF" . implode(',', array_map($cell, ['Receipt', 'Sent in', 'By', 'Supplier', 'Number', 'Date', 'Amount', 'State', 'Note', 'File'])) . "\r\n";
        foreach ($rows as $r) {
            echo implode(',', array_map($cell, [$r['id'], substr((string)$r['uploaded_at'], 0, 16), $r['uploader'], $r['supplier'] ?: $r['vendor'], $r['number'], $r['date'],
                                                 $r['amount'] !== null ? number_format($r['amount'], 2, '.', '') : '', $r['state_label'], $r['note'], $r['name']])) . "\r\n";
        }
        exit;
    }

    private static function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if (!$ids) throw new RcProblem('Choose at least one receipt.', 422);
        if (count($ids) > 200) throw new RcProblem('Up to 200 receipts at a time.', 422);
        return $ids;
    }
}
