<?php
// /crm/ajax/compliance_check.php
// Returns the compliance status for a single account along with blocking flag and lists of missing/expiring types.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../includes/compliance.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

// Support GET or POST for account_id
$accountId = 0;
if (isset($_GET['account_id'])) {
    $accountId = (int)$_GET['account_id'];
} elseif (isset($_POST['account_id'])) {
    $accountId = (int)$_POST['account_id'];
}

try {
    if (!$accountId) {
        throw new Exception('account_id is required');
    }

    $state = crm_compliance_state($DB, $companyId, $accountId);

    echo json_encode([
        'status' => $state['status'],
        'blocking' => $state['blocking'],
        'missing_types' => $state['missing_types'],
        'expiring_types' => $state['expiring_types']
    ]);

} catch (Throwable $e) {
    error_log('CRM compliance_check error: ' . $e->getMessage());
    echo json_encode(['error' => crm_public_error($e)]);
}
