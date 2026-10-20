<?php
/**
 * FlowDriveBackfill — publishes a company's EXISTING documents into FlowWork
 * Drive: renders PDFs for every invoice / quote / credit note, files stored
 * compliance uploads and the slips behind posted supplier bills (Receipts),
 * and hides the legacy seeded folder skeleton.
 *
 * Two entry points:
 *  - runForCompany(): the full sweep, used by qi/cron/backfill_flowdrive.php
 *    (CLI for all companies, browser for the admin's own company).
 *  - autoRunOnce(): self-healing hook called from page loads (home, Quotes &
 *    Invoices). The first time, it publishes the company's documents in
 *    slices of a few seconds per page load — each load resumes where the
 *    previous one stopped (guarded by a company_settings cursor + GET_LOCK) —
 *    until everything is in; after that it is a cheap SELECT, plus a daily
 *    sweep of changed documents that is bounded the same way. This removes the
 *    "someone must remember to run the backfill" operational step without
 *    ever holding a page up for long.
 *
 * Everything is best-effort and never throws.
 */

require_once __DIR__ . '/FlowDriveRepo.php';
require_once __DIR__ . '/FlowDriveSync.php';
require_once __DIR__ . '/../../qi/services/DocumentPdfService.php';

class FlowDriveBackfill
{
    const DONE_FLAG    = 'flowdrive_backfill_done';
    const CURSOR_FLAG  = 'flowdrive_backfill_cursor';
    const SWEEP_FLAG   = 'flowdrive_last_sweep';
    const SWEEP_CURSOR = 'flowdrive_sweep_cursor';
    const RECEIPTS_FLAG = 'flowdrive_receipts_check';
    const RECEIPTS_CURSOR = 'flowdrive_receipts_cursor';

    /** How often page loads look for posted receipts not yet in the drive. */
    const RECEIPTS_INTERVAL_SECONDS = 3600;

    /** How often the self-healing sweep may run per company. */
    const SWEEP_INTERVAL_SECONDS = 86400; // 24h

    /** Upper bound of documents a single sweep will re-publish. */
    const SWEEP_LIMIT = 100;

    /** Seconds one page load may spend publishing (first backfill or daily sweep). */
    const PAGE_BUDGET_SECONDS = 2.5;

    /** Rows read per query while backfilling (keeps memory flat on large companies). */
    const BATCH = 500;

    /**
     * Publish everything the company already has. Returns
     * ['ok' => n, 'fail' => n, 'complete' => bool, 'since' => ?string].
     * $out receives human-readable progress lines.
     *
     * Without a deadline (CLI / browser backfill) everything is done in one
     * go. With one (page loads) it stops once the deadline has passed — after
     * at least one document — remembers per source the last id it published,
     * and the next call carries on from there. Only reaching the end marks the
     * backfill done; 'since' is when the backfill began (the daily sweep's
     * starting point).
     */
    public static function runForCompany(PDO $db, int $companyId, ?callable $out = null, ?float $deadline = null): array
    {
        $out = $out ?: function (string $line) {};
        $counts = ['ok' => 0, 'fail' => 0, 'complete' => false, 'since' => null];

        $cursor = $deadline !== null ? self::loadCursor($db, $companyId) : [];
        if (empty($cursor['since'])) {
            $cursor['since'] = self::dbNow($db);
        }
        $published = 0;
        $outOfTime = function () use (&$published, $deadline): bool {
            return $deadline !== null && $published > 0 && microtime(true) >= $deadline;
        };
        $pause = function () use ($db, $companyId, &$cursor, &$counts): array {
            self::setSetting($db, $companyId, self::CURSOR_FLAG, (string)json_encode($cursor));
            $counts['since'] = $cursor['since'];
            return $counts;
        };

        $docSets = [
            ['type' => 'invoice',     'table' => 'invoices',     'number' => 'invoice_number'],
            ['type' => 'quote',       'table' => 'quotes',       'number' => 'quote_number'],
            ['type' => 'credit_note', 'table' => 'credit_notes', 'number' => 'credit_note_number'],
        ];

        foreach ($docSets as $set) {
            $where = "company_id = ? AND id > ?";
            if (self::hasColumn($db, $set['table'], 'deleted_at')) {
                $where .= " AND deleted_at IS NULL";
            }
            do {
                try {
                    $stmt = $db->prepare("SELECT id, {$set['number']} AS number FROM {$set['table']} WHERE $where ORDER BY id LIMIT " . self::BATCH);
                    $stmt->execute([$companyId, (int)($cursor[$set['type']] ?? 0)]);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {
                    $out("  {$set['table']}: skipped (" . $e->getMessage() . ")");
                    break;
                }

                foreach ($rows as $row) {
                    if ($outOfTime()) {
                        return $pause();
                    }
                    $r = DocumentPdfService::generateAndFile($db, $companyId, $set['type'], (int)$row['id']);
                    if ($r !== null) {
                        $counts['ok']++;
                        $out("  {$set['type']} {$row['number']}: OK");
                    } else {
                        $counts['fail']++;
                        $out("  {$set['type']} {$row['number']}: FAILED (see error log)");
                    }
                    $cursor[$set['type']] = (int)$row['id'];
                    $published++;
                }
            } while (count($rows) === self::BATCH);
        }

        // Existing compliance uploads → {Account}/Documents
        $appRoot = dirname(__DIR__, 2);
        do {
            try {
                $stmt = $db->prepare(
                    "SELECT id, account_id, type_id, reference_no, file_path
                       FROM crm_compliance_docs
                      WHERE company_id = ? AND id > ? AND file_path IS NOT NULL AND file_path <> ''
                      ORDER BY id LIMIT " . self::BATCH
                );
                $stmt->execute([$companyId, (int)($cursor['compliance'] ?? 0)]);
                $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $docs = [];
                $out("  compliance docs: skipped (" . $e->getMessage() . ")");
            }

            foreach ($docs as $doc) {
                if ($outOfTime()) {
                    return $pause();
                }
                $rel = ltrim((string)$doc['file_path'], '/');
                $abs = $appRoot . '/' . $rel;
                if (!is_file($abs)) {
                    // Rescue: the supplier-portal uploader used to climb one
                    // directory too high (see portal/supplier/upload_compliance.php),
                    // stranding files one level ABOVE the app root. If the file is
                    // there, re-home it to the recorded path.
                    $stranded = dirname($appRoot) . '/' . $rel;
                    if (is_file($stranded)) {
                        $dir = dirname($abs);
                        if (!is_dir($dir)) {
                            @mkdir($dir, 0775, true);
                        }
                        if (@copy($stranded, $abs)) {
                            $out("  compliance doc #{$doc['id']}: rescued stranded file into /$rel");
                        }
                    }
                }
                $nodeId = FlowDriveSync::fileComplianceDoc(
                    $db,
                    $companyId,
                    (int)$doc['account_id'],
                    (int)$doc['type_id'],
                    (string)($doc['reference_no'] ?? ''),
                    $abs,
                    null,
                    (int)$doc['id']
                );
                if ($nodeId !== null) {
                    $counts['ok']++;
                    $out("  compliance doc #{$doc['id']}: OK");
                } else {
                    $counts['fail']++;
                    $out("  compliance doc #{$doc['id']}: FAILED (file missing or drive unavailable)");
                }
                $cursor['compliance'] = (int)$doc['id'];
                $published++;
            }
        } while (count($docs) === self::BATCH);

        // Slips behind posted supplier bills → Suppliers/{Account}/Receipts
        $rc = self::publishReceipts($db, $companyId, (int)($cursor['receipt'] ?? 0), function () use ($outOfTime, &$published): bool {
            if ($outOfTime()) {
                return true;
            }
            $published++;
            return false;
        }, $out);
        $counts['ok'] += $rc['ok'];
        $counts['fail'] += $rc['fail'];
        $cursor['receipt'] = $rc['cursor'];
        if (!$rc['complete']) {
            return $pause();
        }

        self::hideLegacySkeleton($db, $companyId, $out);
        self::markDone($db, $companyId);
        self::setSetting($db, $companyId, self::CURSOR_FLAG, '');

        $counts['complete'] = true;
        $counts['since'] = $cursor['since'];
        return $counts;
    }

    /**
     * Self-heal entry point called on page loads:
     *  - until the first backfill is complete → the next slice of it
     *    (at most PAGE_BUDGET_SECONDS of publishing per load);
     *  - thereafter, at most once a day → light sweep that re-publishes
     *    documents whose rows changed since the last sweep (catches anything
     *    a lifecycle hook missed, e.g. a failure mid-webhook), equally bounded.
     * Cheap on every other load (two flag SELECTs). Never throws.
     * Returns 'ran' | 'partial' | 'swept' | 'skipped' | 'unavailable'
     * (for tests/logging).
     */
    public static function autoRunOnce(PDO $db, int $companyId): string
    {
        try {
            if ($companyId <= 0 || !FlowDriveRepo::available($db)) {
                return 'unavailable';
            }
            if (self::isDone($db, $companyId)) {
                // One budget per page load: receipts still being filed wait out the sweep.
                if (self::receiptsCatchUp($db, $companyId) === 'partial') {
                    return 'partial';
                }
                return self::maybeSweep($db, $companyId);
            }
            // Non-blocking lock: if another request is already backfilling,
            // don't make this one wait — it will be done shortly.
            $lockName = 'fw_flowdrive_bf_' . $companyId;
            $stmt = $db->prepare("SELECT GET_LOCK(?, 0)");
            $stmt->execute([$lockName]);
            if ((int)$stmt->fetchColumn() !== 1) {
                return 'skipped';
            }
            try {
                if (self::isDone($db, $companyId)) {
                    return 'skipped';
                }
                $res = self::runForCompany($db, $companyId, null, microtime(true) + self::PAGE_BUDGET_SECONDS);
                if (empty($res['complete'])) {
                    return 'partial';
                }
                // The first sweep looks back to when the backfill began, so a
                // document changed while it ran over several page loads is
                // still caught.
                self::setSetting($db, $companyId, self::SWEEP_FLAG, (string)($res['since'] ?: self::dbNow($db)));
                return 'ran';
            } finally {
                try {
                    $rel = $db->prepare("SELECT RELEASE_LOCK(?)");
                    $rel->execute([$lockName]);
                } catch (Throwable $e) {
                    // connection teardown releases it anyway
                }
            }
        } catch (Throwable $e) {
            error_log('FlowDriveBackfill::autoRunOnce: ' . $e->getMessage());
            return 'unavailable';
        }
    }

    /**
     * Once-a-day re-publish of documents changed since the last sweep.
     * The publish path deliberately preserves updated_at (see
     * DocumentPdfService), so only genuine business changes match here.
     */
    private static function maybeSweep(PDO $db, int $companyId): string
    {
        $last = self::getSetting($db, $companyId, self::SWEEP_FLAG);
        if ($last !== null && (time() - strtotime($last)) < self::SWEEP_INTERVAL_SECONDS) {
            return 'skipped';
        }
        $lockName = 'fw_flowdrive_sw_' . $companyId;
        $stmt = $db->prepare("SELECT GET_LOCK(?, 0)");
        $stmt->execute([$lockName]);
        if ((int)$stmt->fetchColumn() !== 1) {
            return 'skipped';
        }
        try {
            $last = self::getSetting($db, $companyId, self::SWEEP_FLAG);
            if ($last !== null && (time() - strtotime($last)) < self::SWEEP_INTERVAL_SECONDS) {
                return 'skipped';
            }
            // Where an unfinished sweep stopped inside the second it was
            // stamped with ("<stamp>|<source>:<id>"); it only applies to that stamp.
            $after = '';
            $saved = (string)self::getSetting($db, $companyId, self::SWEEP_CURSOR);
            if ($last !== null && $saved !== '' && strpos($saved, $last . '|') === 0) {
                $after = substr($saved, strlen($last) + 1);
            }
            // Stamp first so a failing sweep cannot retry-storm on every load.
            // The stamp uses the DATABASE clock: it is compared against
            // updated_at values MySQL wrote with NOW(), so both sides of the
            // comparison must come from the same clock/timezone.
            $now = self::dbNow($db);
            self::setSetting($db, $companyId, self::SWEEP_FLAG, $now);
            $since = $last ?: date('Y-m-d H:i:s', strtotime($now) - 7 * 86400);
            $res = self::sweepForCompany($db, $companyId, $since, microtime(true) + self::PAGE_BUDGET_SECONDS, $after);
            // If the budget ran out, roll the stamp back to the newest
            // updated_at actually processed and remember the last document
            // published at that exact time: the overflow stays inside the
            // window, the aged stamp lets a later page load continue draining,
            // and documents sharing one updated_at (a bulk update) are worked
            // through instead of the same first ones being redone forever.
            // Without this, documents beyond the budget would fall out of the
            // window for good, since publishing preserves updated_at.
            if (!empty($res['exhausted']) && !empty($res['max_updated'])) {
                self::setSetting($db, $companyId, self::SWEEP_FLAG, $res['max_updated']);
                self::setSetting($db, $companyId, self::SWEEP_CURSOR, $res['max_updated'] . '|' . $res['cursor']);
            } else {
                self::setSetting($db, $companyId, self::SWEEP_CURSOR, '');
            }
            return 'swept';
        } finally {
            try {
                $rel = $db->prepare("SELECT RELEASE_LOCK(?)");
                $rel->execute([$lockName]);
            } catch (Throwable $e) {
                // connection teardown releases it anyway
            }
        }
    }

    /**
     * Re-publish every document whose row changed since $since. Candidates
     * from all sources are gathered and processed OLDEST-FIRST — ordered by
     * (updated_at, source, id) — across one shared budget (no source can
     * starve another), and the caller learns whether the budget (count or
     * $deadline) ran out and how far the sweep actually got ('max_updated' and
     * 'cursor' = "<source>:<id>" of the last document), so the window is only
     * advanced past work really done. $after is such a cursor from a previous
     * unfinished sweep whose stamp equals $since: the documents up to it are
     * not redone.
     *
     * @return array{ok:int, fail:int, exhausted:bool, max_updated:?string, cursor:?string}
     */
    public static function sweepForCompany(PDO $db, int $companyId, string $since, ?float $deadline = null, string $after = ''): array
    {
        $counts = ['ok' => 0, 'fail' => 0, 'exhausted' => false, 'max_updated' => null, 'cursor' => null];
        $fetch = self::SWEEP_LIMIT + 1; // +1 per source so overflow is detectable

        $afterPos = -1;
        $afterId = 0;
        if (preg_match('/^(\d):(\d+)$/', $after, $m)) {
            $afterPos = (int)$m[1];
            $afterId = (int)$m[2];
        }
        // Sources before the cursor's are done for the second $since; the
        // cursor's own source continues after its id; later ones start at $since.
        $window = static function (int $pos) use ($since, $afterPos, $afterId): array {
            if ($afterPos < 0 || $pos > $afterPos) {
                return ['updated_at >= ?', [$since]];
            }
            if ($pos < $afterPos) {
                return ['updated_at > ?', [$since]];
            }
            return ['(updated_at > ? OR (updated_at = ? AND id > ?))', [$since, $since, $afterId]];
        };

        $candidates = [];
        $docSets = [
            ['type' => 'invoice',     'table' => 'invoices'],
            ['type' => 'quote',       'table' => 'quotes'],
            ['type' => 'credit_note', 'table' => 'credit_notes'],
        ];
        foreach ($docSets as $pos => $set) {
            [$cond, $params] = $window($pos);
            $where = "company_id = ? AND $cond";
            if (self::hasColumn($db, $set['table'], 'deleted_at')) {
                $where .= " AND deleted_at IS NULL";
            }
            try {
                $stmt = $db->prepare("SELECT id, updated_at FROM {$set['table']} WHERE $where ORDER BY updated_at ASC, id ASC LIMIT $fetch");
                $stmt->execute(array_merge([$companyId], $params));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $candidates[] = ['kind' => $set['type'], 'pos' => $pos, 'row' => $row, 'u' => (string)$row['updated_at']];
                }
            } catch (Throwable $e) {
                error_log('FlowDriveBackfill::sweep ' . $set['table'] . ': ' . $e->getMessage());
            }
        }
        try {
            $pos = count($docSets);
            [$cond, $params] = $window($pos);
            $stmt = $db->prepare(
                "SELECT id, account_id, type_id, reference_no, file_path, updated_at
                   FROM crm_compliance_docs
                  WHERE company_id = ? AND $cond
                    AND file_path IS NOT NULL AND file_path <> ''
                  ORDER BY updated_at ASC, id ASC LIMIT $fetch"
            );
            $stmt->execute(array_merge([$companyId], $params));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $candidates[] = ['kind' => 'compliance', 'pos' => $pos, 'row' => $row, 'u' => (string)$row['updated_at']];
            }
        } catch (Throwable $e) {
            error_log('FlowDriveBackfill::sweep compliance: ' . $e->getMessage());
        }

        usort($candidates, static function (array $a, array $b) {
            $c = strcmp($a['u'], $b['u']);
            return $c !== 0 ? $c : ([$a['pos'], (int)$a['row']['id']] <=> [$b['pos'], (int)$b['row']['id']]);
        });

        $appRoot = dirname(__DIR__, 2);
        $processed = 0;
        foreach ($candidates as $c) {
            if ($processed >= self::SWEEP_LIMIT
                || ($deadline !== null && $processed > 0 && microtime(true) >= $deadline)) {
                break;
            }
            if ($c['kind'] === 'compliance') {
                $doc = $c['row'];
                $abs = $appRoot . '/' . ltrim((string)$doc['file_path'], '/');
                $nodeId = FlowDriveSync::fileComplianceDoc(
                    $db, $companyId, (int)$doc['account_id'], (int)$doc['type_id'],
                    (string)($doc['reference_no'] ?? ''), $abs, null, (int)$doc['id']
                );
                $counts[$nodeId !== null ? 'ok' : 'fail']++;
            } else {
                $r = DocumentPdfService::generateAndFile($db, $companyId, $c['kind'], (int)$c['row']['id']);
                $counts[$r !== null ? 'ok' : 'fail']++;
            }
            $processed++;
            $counts['max_updated'] = $c['u'];
            $counts['cursor'] = $c['pos'] . ':' . (int)$c['row']['id'];
        }
        $counts['exhausted'] = $processed < count($candidates);

        return $counts;
    }

    /**
     * File posted receipts that are not in the drive yet (Receipts' RcDrive),
     * from file id $after on, until $stop() says the time is up. Each one is
     * marked in receipt_file once filed, so nothing is filed twice.
     * @return array{ok:int, fail:int, cursor:int, complete:bool}
     */
    public static function publishReceipts(PDO $db, int $companyId, int $after = 0, ?callable $stop = null, ?callable $out = null): array
    {
        $res = ['ok' => 0, 'fail' => 0, 'cursor' => $after, 'complete' => true];
        $rcDrive = dirname(__DIR__, 2) . '/receipts/lib/RcDrive.php';
        if (!is_file($rcDrive)) {
            return $res;
        }
        require_once $rcDrive;
        do {
            $ids = RcDrive::pending($db, $companyId, $res['cursor'], self::BATCH);
            foreach ($ids as $id) {
                if ($stop !== null && $stop()) {
                    $res['complete'] = false;
                    return $res;
                }
                $node = RcDrive::publish($db, $companyId, $id);
                $res[$node !== null ? 'ok' : 'fail']++;
                if ($out !== null) {
                    $out("  receipt #$id: " . ($node !== null ? 'OK' : 'FAILED (file missing, too large or drive unavailable)'));
                }
                $res['cursor'] = $id;
            }
        } while (count($ids) === self::BATCH);
        return $res;
    }

    /**
     * Page-load catch-up for receipts, once the first backfill is done: at
     * most hourly, a few seconds at a time, files posted receipts the posting
     * itself could not (the drive was down) and, after Receipts arrives on a
     * company or filing is switched back on, the older ones. Until a pass
     * gets through them all, the next page load carries on where it stopped
     * (so a slip that keeps failing never holds the others up).
     */
    private static function receiptsCatchUp(PDO $db, int $companyId): string
    {
        $last = self::getSetting($db, $companyId, self::RECEIPTS_FLAG);
        if ($last !== null && $last !== '' && (time() - strtotime($last)) < self::RECEIPTS_INTERVAL_SECONDS) {
            return 'skipped';
        }
        $lockName = 'fw_flowdrive_rc_' . $companyId;
        $stmt = $db->prepare("SELECT GET_LOCK(?, 0)");
        $stmt->execute([$lockName]);
        if ((int)$stmt->fetchColumn() !== 1) {
            return 'skipped';
        }
        try {
            $deadline = microtime(true) + self::PAGE_BUDGET_SECONDS;
            $done = 0;
            $after = (int)self::getSetting($db, $companyId, self::RECEIPTS_CURSOR);
            $res = self::publishReceipts($db, $companyId, $after, function () use (&$done, $deadline): bool {
                return $done++ > 0 && microtime(true) >= $deadline;
            });
            self::setSetting($db, $companyId, self::RECEIPTS_CURSOR, $res['complete'] ? '' : (string)$res['cursor']);
            if ($res['complete']) {
                self::setSetting($db, $companyId, self::RECEIPTS_FLAG, date('Y-m-d H:i:s'));
            }
            return $res['complete'] ? 'ran' : 'partial';
        } finally {
            try {
                $rel = $db->prepare("SELECT RELEASE_LOCK(?)");
                $rel->execute([$lockName]);
            } catch (Throwable $e) {
                // connection teardown releases it anyway
            }
        }
    }

    // ------------------------------------------------------------------

    private static function isDone(PDO $db, int $companyId): bool
    {
        return self::getSetting($db, $companyId, self::DONE_FLAG) === '1';
    }

    private static function markDone(PDO $db, int $companyId): void
    {
        self::setSetting($db, $companyId, self::DONE_FLAG, '1');
    }

    /** Where an unfinished page-load backfill stopped: ['since' => …, '<source>' => last id]. */
    private static function loadCursor(PDO $db, int $companyId): array
    {
        $v = json_decode((string)self::getSetting($db, $companyId, self::CURSOR_FLAG), true);
        return is_array($v) ? $v : [];
    }

    /** The database server's clock — the same source that writes updated_at. */
    private static function dbNow(PDO $db): string
    {
        try {
            $v = $db->query("SELECT NOW()")->fetchColumn();
            if (is_string($v) && $v !== '') {
                return $v;
            }
        } catch (Throwable $e) {
            // fall through to the PHP clock
        }
        return date('Y-m-d H:i:s');
    }

    private static function getSetting(PDO $db, int $companyId, string $key): ?string
    {
        $stmt = $db->prepare("SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = ?");
        $stmt->execute([$companyId, $key]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    private static function setSetting(PDO $db, int $companyId, string $key, string $value): void
    {
        try {
            $stmt = $db->prepare(
                "INSERT INTO company_settings (company_id, setting_key, setting_value)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $stmt->execute([$companyId, $key, $value]);
        } catch (Throwable $e) {
            error_log('FlowDriveBackfill::setSetting(' . $key . '): ' . $e->getMessage());
        }
    }

    private static function hasColumn(PDO $db, string $table, string $column): bool
    {
        // SHOW statements reject parameter markers under native prepares, so
        // inline the (internal, quoted) literal.
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->quote($column));
            return (bool)$stmt->fetch();
        } catch (Throwable $e) {
            error_log('FlowDriveBackfill::hasColumn: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * The original FlowDrive seed hung an internal/clients skeleton under a
     * hidden '/' node. The web UI never showed it, but WebDAV (phone app,
     * desktop mounts) lists it next to the real Customers/Suppliers tree.
     * Soft-hide it — but ONLY when it contains no genuine files (the seed's
     * own test file doesn't count). Reversible by clearing deleted_at.
     */
    private static function hideLegacySkeleton(PDO $db, int $companyId, callable $out): void
    {
        try {
            $stmt = $db->prepare("SELECT id FROM fd_drives WHERE type='company' AND subtype='flowwork' AND company_id = ? ORDER BY id LIMIT 1");
            $stmt->execute([$companyId]);
            $driveId = (int)$stmt->fetchColumn();
            if (!$driveId) {
                return;
            }
            $stmt = $db->prepare("SELECT id FROM fd_nodes WHERE drive_id = ? AND parent_id IS NULL AND name = '/' AND type = 'folder' AND deleted_at IS NULL LIMIT 1");
            $stmt->execute([$driveId]);
            $rootId = (int)$stmt->fetchColumn();
            if (!$rootId) {
                return;
            }

            $stmt = $db->prepare(
                "WITH RECURSIVE sub AS (
                     SELECT id FROM fd_nodes WHERE parent_id = ? AND deleted_at IS NULL
                     UNION ALL
                     SELECT n.id FROM fd_nodes n JOIN sub s ON n.parent_id = s.id WHERE n.deleted_at IS NULL
                 )
                 SELECT s.id, n.type, n.name FROM sub s JOIN fd_nodes n ON n.id = s.id"
            );
            $stmt->execute([$rootId]);
            $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$nodes) {
                return;
            }

            foreach ($nodes as $n) {
                if ($n['type'] === 'file' && $n['name'] !== 'Test_Payslip_001') {
                    $out("  legacy skeleton kept (contains real file: {$n['name']})");
                    return;
                }
            }

            $ids = array_map('intval', array_column($nodes, 'id'));
            $in  = implode(',', $ids);
            $db->exec("UPDATE fd_nodes SET deleted_at = NOW() WHERE id IN ($in)");
            $out('  legacy internal/clients skeleton hidden (' . count($ids) . ' empty nodes)');
        } catch (Throwable $e) {
            error_log('FlowDriveBackfill::hideLegacySkeleton: ' . $e->getMessage());
            $out('  legacy skeleton cleanup skipped (' . $e->getMessage() . ')');
        }
    }
}
