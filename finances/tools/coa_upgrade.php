<?php
// /finances/tools/coa_upgrade.php — One-time admin tool to upgrade existing
// companies from the old 41-account seed to the full SARS chart of accounts.
//
// Safe to run multiple times: seed_sars_chart_of_accounts uses INSERT IGNORE,
// so existing (company_id, account_code) rows are preserved.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin']);

header('Content-Type: application/json');

$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];

// GET = preview, POST = execute
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // Count current accounts
        $stmt = $DB->prepare("SELECT COUNT(*) FROM gl_accounts WHERE company_id = ?");
        $stmt->execute([$companyId]);
        $currentCount = (int)$stmt->fetchColumn();

        // Count how many have empty subtype (old-style)
        $stmt = $DB->prepare("SELECT COUNT(*) FROM gl_accounts WHERE company_id = ? AND account_subtype = ''");
        $stmt->execute([$companyId]);
        $emptySubtype = (int)$stmt->fetchColumn();

        // Count posted journal entries
        $stmt = $DB->prepare("SELECT COUNT(*) FROM journal_entries WHERE company_id = ? AND status = 'posted'");
        $stmt->execute([$companyId]);
        $postedJournals = (int)$stmt->fetchColumn();

        echo json_encode([
            'ok' => true,
            'preview' => [
                'current_accounts'  => $currentCount,
                'empty_subtype'     => $emptySubtype,
                'posted_journals'   => $postedJournals,
                'action'            => 'Will INSERT IGNORE ~108 SARS accounts and UPDATE metadata on existing rows. No accounts will be deleted.',
            ],
        ]);

    } elseif ($method === 'POST') {
        require_once __DIR__ . '/../lib/Csrf.php';
        Csrf::validate();

        $DB->beginTransaction();

        // Run the seed procedure (INSERT IGNORE — safe)
        $stmt = $DB->prepare("CALL seed_sars_chart_of_accounts(?)");
        $stmt->execute([$companyId]);

        // Count after
        $stmt = $DB->prepare("SELECT COUNT(*) FROM gl_accounts WHERE company_id = ?");
        $stmt->execute([$companyId]);
        $afterCount = (int)$stmt->fetchColumn();

        // Audit
        $stmt = $DB->prepare("
            INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
            VALUES (?, ?, 'coa_upgraded', ?, ?, NOW())
        ");
        $stmt->execute([
            $companyId, $userId,
            json_encode(['accounts_after' => $afterCount]),
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        $DB->commit();

        echo json_encode([
            'ok' => true,
            'data' => ['accounts_after' => $afterCount],
        ]);

    } else {
        echo json_encode(['ok' => false, 'error' => 'Use GET to preview, POST to execute']);
    }

} catch (Exception $e) {
    if ($DB->inTransaction()) $DB->rollBack();
    error_log("COA upgrade error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
