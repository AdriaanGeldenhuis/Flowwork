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
if (!$isCli) {
    require_once __DIR__ . '/../../auth_gate.php';
    $role = $_SESSION['role'] ?? '';
    if (empty($_SESSION['is_admin']) && !in_array($role, ['admin', 'owner'], true)) {
        http_response_code(403);
        exit('Admin access required');
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

try {
    $companies = $DB->query("SELECT id, name FROM companies")->fetchAll(PDO::FETCH_ASSOC);

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
            $abs = __DIR__ . '/../../' . ltrim((string)$doc['file_path'], '/');
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
    }

    $out("");
    $out("Done. {$counts['ok']} published, {$counts['fail']} failed.");
} catch (Throwable $e) {
    error_log('backfill_flowdrive: ' . $e->getMessage());
    $out("FATAL: " . $e->getMessage());
}
