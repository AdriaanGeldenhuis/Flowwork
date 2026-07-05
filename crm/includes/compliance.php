<?php
// /crm/includes/compliance.php
// Shared compliance evaluation, used by the compliance_check endpoint and by
// flows that must refuse blocked suppliers (e.g. RFQ creation).

/**
 * Evaluate the compliance state of an account.
 *
 * Returns:
 *   [
 *     'status'         => 'valid'|'missing'|'expired'|'expiring',
 *     'blocking'       => bool,   // true when the company blocks non-compliant suppliers
 *     'missing_types'  => string[] (type codes),
 *     'expiring_types' => string[],
 *     'expired_types'  => string[],
 *   ]
 *
 * @throws Exception when the account does not exist in this company.
 */
function crm_compliance_state(PDO $DB, int $companyId, int $accountId): array
{
    $stmt = $DB->prepare("SELECT type FROM crm_accounts WHERE id = ? AND company_id = ?");
    $stmt->execute([$accountId, $companyId]);
    $accountType = $stmt->fetchColumn();
    if (!$accountType) {
        throw new Exception('Account not found');
    }

    // Company compliance policy
    $settingsStmt = $DB->prepare("
        SELECT setting_key, setting_value
        FROM company_settings
        WHERE company_id = ? AND setting_key IN ('crm_block_expired_suppliers', 'crm_reminder_days')
    ");
    $settingsStmt->execute([$companyId]);
    $settings = [];
    foreach ($settingsStmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    $blockExpired = (int)($settings['crm_block_expired_suppliers'] ?? 0);
    $reminderDaysArr = array_filter(array_map(
        'intval',
        preg_split('/[,;\s]+/', $settings['crm_reminder_days'] ?? '30,14,7')
    ));
    $reminderThreshold = count($reminderDaysArr) > 0 ? max($reminderDaysArr) : 0;

    // Required doc types vs this account's docs
    $typesStmt = $DB->prepare("SELECT id, code FROM crm_compliance_types WHERE company_id = ? AND required = 1");
    $typesStmt->execute([$companyId]);
    $requiredTypes = $typesStmt->fetchAll(PDO::FETCH_ASSOC);

    $docsStmt = $DB->prepare("SELECT type_id, expiry_date FROM crm_compliance_docs WHERE company_id = ? AND account_id = ?");
    $docsStmt->execute([$companyId, $accountId]);
    $docsByType = [];
    foreach ($docsStmt->fetchAll(PDO::FETCH_ASSOC) as $doc) {
        $docsByType[$doc['type_id']] = $doc;
    }

    $missingTypes = [];
    $expiringTypes = [];
    $expiredTypes = [];
    $now = new DateTime();

    foreach ($requiredTypes as $type) {
        if (!isset($docsByType[$type['id']])) {
            $missingTypes[] = $type['code'];
            continue;
        }
        $doc = $docsByType[$type['id']];
        if (!empty($doc['expiry_date'])) {
            $expiryDate = new DateTime($doc['expiry_date']);
            $diff = (int)$now->diff($expiryDate)->format('%r%a');
            if ($diff < 0) {
                $expiredTypes[] = $type['code'];
            } elseif ($diff <= $reminderThreshold) {
                $expiringTypes[] = $type['code'];
            }
        }
    }

    $status = 'valid';
    if (!empty($missingTypes)) {
        $status = 'missing';
    } elseif (!empty($expiredTypes)) {
        $status = 'expired';
    } elseif (!empty($expiringTypes)) {
        $status = 'expiring';
    }

    // Blocking applies to suppliers only, when the policy is enabled
    $blocking = $accountType === 'supplier'
        && $blockExpired
        && ($status === 'expired' || $status === 'missing');

    return [
        'status'         => $status,
        'blocking'       => $blocking,
        'missing_types'  => $missingTypes,
        'expiring_types' => $expiringTypes,
        'expired_types'  => $expiredTypes,
    ];
}
