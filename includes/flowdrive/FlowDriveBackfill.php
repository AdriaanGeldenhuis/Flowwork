<?php
/**
 * FlowDriveBackfill — publishes a company's EXISTING documents into FlowWork
 * Drive: renders PDFs for every invoice / quote / credit note, files stored
 * compliance uploads, and hides the legacy seeded folder skeleton.
 *
 * Two entry points:
 *  - runForCompany(): the full sweep, used by qi/cron/backfill_flowdrive.php
 *    (CLI for all companies, browser for the admin's own company).
 *  - autoRunOnce(): self-healing hook called from the QI dashboard endpoints —
 *    runs the sweep the FIRST time the dashboard is opened after deployment
 *    (guarded by a company_settings flag + GET_LOCK) and is a single cheap
 *    SELECT afterwards. This removes the "someone must remember to run the
 *    backfill" operational step: the drive populates itself.
 *
 * Everything is best-effort and never throws.
 */

require_once __DIR__ . '/FlowDriveRepo.php';
require_once __DIR__ . '/FlowDriveSync.php';
require_once __DIR__ . '/../../qi/services/DocumentPdfService.php';

class FlowDriveBackfill
{
    const DONE_FLAG  = 'flowdrive_backfill_done';
    const SWEEP_FLAG = 'flowdrive_last_sweep';

    /** How often the self-healing sweep may run per company. */
    const SWEEP_INTERVAL_SECONDS = 86400; // 24h

    /** Upper bound of documents a single sweep will re-publish. */
    const SWEEP_LIMIT = 100;

    /**
     * Publish everything the company already has. Returns
     * ['ok' => n, 'fail' => n]. $out receives human-readable progress lines.
     */
    public static function runForCompany(PDO $db, int $companyId, ?callable $out = null): array
    {
        $out = $out ?: function (string $line) {};
        $counts = ['ok' => 0, 'fail' => 0];

        $docSets = [
            ['type' => 'invoice',     'table' => 'invoices',     'number' => 'invoice_number'],
            ['type' => 'quote',       'table' => 'quotes',       'number' => 'quote_number'],
            ['type' => 'credit_note', 'table' => 'credit_notes', 'number' => 'credit_note_number'],
        ];

        foreach ($docSets as $set) {
            $where = "company_id = ?";
            if (self::hasColumn($db, $set['table'], 'deleted_at')) {
                $where .= " AND deleted_at IS NULL";
            }
            try {
                $stmt = $db->prepare("SELECT id, {$set['number']} AS number FROM {$set['table']} WHERE $where ORDER BY id");
                $stmt->execute([$companyId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $out("  {$set['table']}: skipped (" . $e->getMessage() . ")");
                continue;
            }

            foreach ($rows as $row) {
                $r = DocumentPdfService::generateAndFile($db, $companyId, $set['type'], (int)$row['id']);
                if ($r !== null) {
                    $counts['ok']++;
                    $out("  {$set['type']} {$row['number']}: OK");
                } else {
                    $counts['fail']++;
                    $out("  {$set['type']} {$row['number']}: FAILED (see error log)");
                }
            }
        }

        // Existing compliance uploads → {Account}/Documents
        try {
            $stmt = $db->prepare(
                "SELECT id, account_id, type_id, reference_no, file_path
                   FROM crm_compliance_docs
                  WHERE company_id = ? AND file_path IS NOT NULL AND file_path <> ''"
            );
            $stmt->execute([$companyId]);
            $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $docs = [];
            $out("  compliance docs: skipped (" . $e->getMessage() . ")");
        }

        $appRoot = dirname(__DIR__, 2);
        foreach ($docs as $doc) {
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
        }

        self::hideLegacySkeleton($db, $companyId, $out);
        self::markDone($db, $companyId);

        return $counts;
    }

    /**
     * Self-heal entry point called on dashboard page loads:
     *  - first call ever for a company → full backfill;
     *  - thereafter, at most once a day → light sweep that re-publishes
     *    documents whose rows changed since the last sweep (catches anything
     *    a lifecycle hook missed, e.g. a failure mid-webhook).
     * Cheap on every other load (two flag SELECTs). Never throws.
     * Returns 'ran' | 'swept' | 'skipped' | 'unavailable' (for tests/logging).
     */
    public static function autoRunOnce(PDO $db, int $companyId): string
    {
        try {
            if ($companyId <= 0 || !FlowDriveRepo::available($db)) {
                return 'unavailable';
            }
            if (self::isDone($db, $companyId)) {
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
                self::runForCompany($db, $companyId);
                self::setSetting($db, $companyId, self::SWEEP_FLAG, self::dbNow($db));
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
            // Stamp first so a failing sweep cannot retry-storm on every load.
            // The stamp uses the DATABASE clock: it is compared against
            // updated_at values MySQL wrote with NOW(), so both sides of the
            // comparison must come from the same clock/timezone.
            $now = self::dbNow($db);
            self::setSetting($db, $companyId, self::SWEEP_FLAG, $now);
            $since = $last ?: date('Y-m-d H:i:s', strtotime($now) - 7 * 86400);
            $res = self::sweepForCompany($db, $companyId, $since);
            // If the budget ran out, roll the stamp back to the newest
            // updated_at actually processed: the overflow stays inside the
            // window (>= is inclusive) and the aged stamp lets the very next
            // page load continue draining instead of waiting a day — without
            // this, documents beyond the budget would fall out of the window
            // forever, since publishing preserves updated_at.
            if (!empty($res['exhausted']) && !empty($res['max_updated'])) {
                self::setSetting($db, $companyId, self::SWEEP_FLAG, $res['max_updated']);
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
     * from all sources are gathered and processed OLDEST-FIRST across one
     * shared budget (no source can starve another), and the caller learns
     * whether the budget ran out and how far the sweep actually got
     * ('max_updated'), so the window is only advanced past work really done.
     *
     * @return array{ok:int, fail:int, exhausted:bool, max_updated:?string}
     */
    public static function sweepForCompany(PDO $db, int $companyId, string $since): array
    {
        $counts = ['ok' => 0, 'fail' => 0, 'exhausted' => false, 'max_updated' => null];
        $fetch = self::SWEEP_LIMIT + 1; // +1 per source so overflow is detectable

        $candidates = [];
        $docSets = [
            ['type' => 'invoice',     'table' => 'invoices'],
            ['type' => 'quote',       'table' => 'quotes'],
            ['type' => 'credit_note', 'table' => 'credit_notes'],
        ];
        foreach ($docSets as $set) {
            $where = "company_id = ? AND updated_at >= ?";
            if (self::hasColumn($db, $set['table'], 'deleted_at')) {
                $where .= " AND deleted_at IS NULL";
            }
            try {
                $stmt = $db->prepare("SELECT id, updated_at FROM {$set['table']} WHERE $where ORDER BY updated_at ASC LIMIT $fetch");
                $stmt->execute([$companyId, $since]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $candidates[] = ['kind' => $set['type'], 'row' => $row, 'u' => (string)$row['updated_at']];
                }
            } catch (Throwable $e) {
                error_log('FlowDriveBackfill::sweep ' . $set['table'] . ': ' . $e->getMessage());
            }
        }
        try {
            $stmt = $db->prepare(
                "SELECT id, account_id, type_id, reference_no, file_path, updated_at
                   FROM crm_compliance_docs
                  WHERE company_id = ? AND updated_at >= ?
                    AND file_path IS NOT NULL AND file_path <> ''
                  ORDER BY updated_at ASC LIMIT $fetch"
            );
            $stmt->execute([$companyId, $since]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $candidates[] = ['kind' => 'compliance', 'row' => $row, 'u' => (string)$row['updated_at']];
            }
        } catch (Throwable $e) {
            error_log('FlowDriveBackfill::sweep compliance: ' . $e->getMessage());
        }

        usort($candidates, static function (array $a, array $b) { return strcmp($a['u'], $b['u']); });
        $counts['exhausted'] = count($candidates) > self::SWEEP_LIMIT;
        $candidates = array_slice($candidates, 0, self::SWEEP_LIMIT);

        $appRoot = dirname(__DIR__, 2);
        foreach ($candidates as $c) {
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
            $counts['max_updated'] = $c['u'];
        }

        return $counts;
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
