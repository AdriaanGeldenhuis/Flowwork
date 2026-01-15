<?php
// /crm/ajax/compliance_check.php
// Returns the compliance status for a single account along with blocking flag and lists of missing/expiring types.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

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

    // Fetch account type
    $stmt = $DB->prepare("SELECT type FROM crm_accounts WHERE id = ? AND company_id = ?");
    $stmt->execute([$accountId, $companyId]);
    $accountType = $stmt->fetchColumn();
    if (!$accountType) {
        throw new Exception('Account not found');
    }

    // Load settings
    $settingsStmt = $DB->prepare("SELECT setting_key, setting_value FROM company_settings WHERE company_id = ? AND setting_key LIKE 'crm_%'");
    $settingsStmt->execute([$companyId]);
    $settings = [];
    foreach ($settingsStmt->fetchAll() as $row) {
        $key = str_replace('crm_', '', $row['setting_key']);
        $settings[$key] = $row['setting_value'];
    }

    // Default settings
    $blockExpired = isset($settings['block_expired_suppliers']) ? (int)$settings['block_expired_suppliers'] : 0;
    $reminderDaysStr = $settings['reminder_days'] ?? '30,14,7';
    // Determine the primary reminder threshold (use the highest value)
    $reminderDaysArr = array_filter(array_map('intval', preg_split('/[,;\s]+/', $reminderDaysStr)));
    $reminderThreshold = 0;
    if (count($reminderDaysArr) > 0) {
        // Use the maximum to catch docs expiring soon; lower values will also be captured.
        $reminderThreshold = max($reminderDaysArr);
    }

    // Fetch required compliance types for company
    $typesStmt = $DB->prepare("SELECT id, code, name FROM crm_compliance_types WHERE company_id = ? AND required = 1");
    $typesStmt->execute([$companyId]);
    $requiredTypes = $typesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch docs for this account
    $docsStmt = $DB->prepare("SELECT type_id, expiry_date, status FROM crm_compliance_docs WHERE company_id = ? AND account_id = ?");
    $docsStmt->execute([$companyId, $accountId]);
    $docs = $docsStmt->fetchAll(PDO::FETCH_ASSOC);
    $docsByType = [];
    foreach ($docs as $doc) {
        $docsByType[$doc['type_id']] = $doc;
    }

    $missingTypes = [];
    $expiringTypes = [];
    $expiredTypes = [];
    $now = new DateTime();

    foreach ($requiredTypes as $type) {
        $tid = $type['id'];
        $code = $type['code'];
        if (!isset($docsByType[$tid])) {
            $missingTypes[] = $code;
            continue;
        }
        $doc = $docsByType[$tid];
        // If expiry_date is null, consider valid
        if (!empty($doc['expiry_date'])) {
            $expiryDate = new DateTime($doc['expiry_date']);
            $diff = (int)$now->diff($expiryDate)->format('%r%a'); // days difference with sign
            if ($diff < 0) {
                $expiredTypes[] = $code;
            } elseif ($diff <= $reminderThreshold) {
                $expiringTypes[] = $code;
            }
        }
    }

    // Determine status
    $status = 'valid';
    if (!empty($missingTypes)) {
        $status = 'missing';
    } elseif (!empty($expiredTypes)) {
        $status = 'expired';
    } elseif (!empty($expiringTypes)) {
        $status = 'expiring';
    }

    // Blocking rule: only for suppliers, and only when expired or missing if block_expired_suppliers is set
    $blocking = false;
    if ($accountType === 'supplier' && $blockExpired) {
        if ($status === 'expired' || $status === 'missing') {
            $blocking = true;
        }
    }

    echo json_encode([
        'status' => $status,
        'blocking' => $blocking,
        'missing_types' => $missingTypes,
        'expiring_types' => $expiringTypes
    ]);

} catch (Exception $e) {
    error_log('CRM compliance_check error: ' . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
