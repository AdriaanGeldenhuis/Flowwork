<?php
// /qi/cron/backfill_flowdrive.php
//
// One-off / re-runnable backfill: renders the PDF for every existing invoice,
// quote and credit note (they only got a PDF at email-time before), stores it
// under storage/qi/{company}/..., and publishes everything — including
// existing CRM compliance uploads — into FlowWork Drive so each
// customer/supplier folder is complete.
//
// Idempotent: re-running re-renders and overwrites the same files/nodes.
//
// Run from the shell:   php qi/cron/backfill_flowdrive.php
// or in the browser as a logged-in admin/owner: /qi/cron/backfill_flowdrive.php

require_once __DIR__ . '/../../init.php';

$isCli = (php_sapi_name() === 'cli');
$onlyCompanyId = null;
if (!$isCli) {
    require_once __DIR__ . '/../../auth_gate.php';
    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, ['admin', 'owner'], true)) {
        http_response_code(403);
        exit('Admin access required');
    }
    // $_SESSION['role'] is a PER-COMPANY role: a tenant admin must only ever
    // backfill (and see output about) their own company. Cross-company runs
    // are CLI-only (server operator).
    $onlyCompanyId = (int)($_SESSION['company_id'] ?? 0);
    if ($onlyCompanyId <= 0) {
        http_response_code(403);
        exit('No active company');
    }
    header('Content-Type: text/plain; charset=UTF-8');
}

require_once __DIR__ . '/../services/DocumentPdfService.php';

set_time_limit(0);
ignore_user_abort(true);

function backfill_has_column(PDO $db, string $table, string $column): bool
{
    // SHOW statements reject parameter markers under native prepares
    // (db.php sets ATTR_EMULATE_PREPARES=false), so inline the literal.
    // $table/$column are internal constants, and quote() belts-and-braces it.
    try {
        $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->quote($column));
        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        error_log('backfill_has_column: ' . $e->getMessage());
        return false;
    }
}

$counts = ['ok' => 0, 'fail' => 0];
$out = function (string $line) {
    echo $line . "\n";
    @flush();
};

/**
 * The original FlowDrive seed hung an internal/clients skeleton under a
 * hidden '/' node. The web UI never showed it, but WebDAV (phone app,
 * desktop mounts) lists it next to the real Customers/Suppliers tree.
 * Once real folders exist, soft-hide that skeleton — but ONLY when it
 * contains no genuine files (the seed's own test file doesn't count).
 * Soft-delete only: fully reversible by clearing deleted_at.
 */
function backfill_hide_legacy_skeleton(PDO $db, int $companyId, callable $out): void
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
        error_log('backfill_hide_legacy_skeleton: ' . $e->getMessage());
        $out('  legacy skeleton cleanup skipped (' . $e->getMessage() . ')');
    }
}

try {
    if ($onlyCompanyId !== null) {
        $stmt = $DB->prepare("SELECT id, name FROM companies WHERE id = ?");
        $stmt->execute([$onlyCompanyId]);
        $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $companies = $DB->query("SELECT id, name FROM companies")->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($companies as $company) {
        $companyId = (int)$company['id'];
        $out("== Company {$companyId}: {$company['name']} ==");

        $docSets = [
            ['type' => 'invoice',     'table' => 'invoices',     'number' => 'invoice_number'],
            ['type' => 'quote',       'table' => 'quotes',       'number' => 'quote_number'],
            ['type' => 'credit_note', 'table' => 'credit_notes', 'number' => 'credit_note_number'],
        ];

        foreach ($docSets as $set) {
            $where = "company_id = ?";
            if (backfill_has_column($DB, $set['table'], 'deleted_at')) {
                $where .= " AND deleted_at IS NULL";
            }
            try {
                $stmt = $DB->prepare("SELECT id, {$set['number']} AS number FROM {$set['table']} WHERE $where ORDER BY id");
                $stmt->execute([$companyId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $out("  {$set['table']}: skipped (" . $e->getMessage() . ")");
                continue;
            }

            foreach ($rows as $row) {
                $r = DocumentPdfService::generateAndFile($DB, $companyId, $set['type'], (int)$row['id']);
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
            $stmt = $DB->prepare(
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

        require_once __DIR__ . '/../../includes/flowdrive/FlowDriveSync.php';
        foreach ($docs as $doc) {
            $rel = ltrim((string)$doc['file_path'], '/');
            $abs = __DIR__ . '/../../' . $rel;
            if (!is_file($abs)) {
                // Rescue: the supplier-portal uploader used to climb one
                // directory too high (see portal/supplier/upload_compliance.php),
                // stranding files one level ABOVE the app root. If the file is
                // there, re-home it to the recorded path.
                $stranded = __DIR__ . '/../../../' . $rel;
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
                $DB,
                $companyId,
                (int)$doc['account_id'],
                (int)$doc['type_id'],
                (string)($doc['reference_no'] ?? ''),
                $abs
            );
            if ($nodeId !== null) {
                $counts['ok']++;
                $out("  compliance doc #{$doc['id']}: OK");
            } else {
                $counts['fail']++;
                $out("  compliance doc #{$doc['id']}: FAILED (file missing or drive unavailable)");
            }
        }

        backfill_hide_legacy_skeleton($DB, $companyId, $out);
    }

    $out("");
    $out("Done. {$counts['ok']} published, {$counts['fail']} failed.");
} catch (Throwable $e) {
    error_log('backfill_flowdrive: ' . $e->getMessage());
    $out("FATAL: " . $e->getMessage());
}
