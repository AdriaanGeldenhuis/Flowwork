<?php
// Returns a list of expense GL accounts for the current company.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

// Ensure the user is authenticated
if (!isset($_SESSION['company_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];

try {
    // Fetch expense accounts for this company.  Use account_code and account_name to provide a readable label.
    $stmt = $DB->prepare(
        "SELECT account_id, account_code, account_name
         FROM gl_accounts
         WHERE company_id = ? AND account_type = 'expense'
         ORDER BY account_code"
    );
    $stmt->execute([$companyId]);
    $accounts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $accounts[] = [
            'id'   => (int)$row['account_id'],
            'code' => $row['account_code'],
            'name' => $row['account_name']
        ];
    }
    echo json_encode(['ok' => true, 'accounts' => $accounts]);
    exit;
} catch (Exception $e) {
    error_log('gl_accounts API error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
    exit;
}