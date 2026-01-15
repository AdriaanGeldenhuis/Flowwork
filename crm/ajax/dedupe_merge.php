<?php
// /crm/ajax/dedupe_merge.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $leftId = (int)($input['left_id'] ?? 0);
    $rightId = (int)($input['right_id'] ?? 0);
    $candidateId = (int)($input['candidate_id'] ?? 0);
    $selectedFields = $input['selected_fields'] ?? [];

    if (!$leftId || !$rightId) {
        throw new Exception('Both account IDs required');
    }

    if ($leftId === $rightId) {
        throw new Exception('Cannot merge account with itself');
    }

    $DB->beginTransaction();

    // Verify both accounts exist and belong to company
    $stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE id = ? AND company_id = ?");
    
    $stmt->execute([$leftId, $companyId]);
    $leftAccount = $stmt->fetch();
    if (!$leftAccount) {
        throw new Exception('Left account not found');
    }

    $stmt->execute([$rightId, $companyId]);
    $rightAccount = $stmt->fetch();
    if (!$rightAccount) {
        throw new Exception('Right account not found');
    }

    // Build merged account data based on selected fields
    $mergedData = [];
    $fields = [
        'name', 'legal_name', 'reg_no', 'vat_no', 'email', 'phone', 
        'website', 'industry_id', 'region_id', 'status', 'notes'
    ];

    foreach ($fields as $field) {
        $selectedSide = $selectedFields[$field] ?? 'left';
        
        if ($selectedSide === 'left') {
            // Get value from left account
            $stmt = $DB->prepare("SELECT {$field} FROM crm_accounts WHERE id = ?");
            $stmt->execute([$leftId]);
            $mergedData[$field] = $stmt->fetchColumn();
        } else {
            // Get value from right account
            $stmt = $DB->prepare("SELECT {$field} FROM crm_accounts WHERE id = ?");
            $stmt->execute([$rightId]);
            $mergedData[$field] = $stmt->fetchColumn();
        }
    }

    // Update left account with merged data
    $stmt = $DB->prepare("
        UPDATE crm_accounts SET
            name = ?,
            legal_name = ?,
            reg_no = ?,
            vat_no = ?,
            email = ?,
            phone = ?,
            website = ?,
            industry_id = ?,
            region_id = ?,
            status = ?,
            notes = ?,
            updated_at = NOW()
        WHERE id = ? AND company_id = ?
    ");

    $stmt->execute([
        $mergedData['name'],
        $mergedData['legal_name'],
        $mergedData['reg_no'],
        $mergedData['vat_no'],
        $mergedData['email'],
        $mergedData['phone'],
        $mergedData['website'],
        $mergedData['industry_id'],
        $mergedData['region_id'],
        $mergedData['status'],
        $mergedData['notes'],
        $leftId,
        $companyId
    ]);

    // Move all related data from right to left

    // 1. Contacts
    $stmt = $DB->prepare("
        UPDATE crm_contacts 
        SET account_id = ?, updated_at = NOW()
        WHERE account_id = ? AND company_id = ?
    ");
    $stmt->execute([$leftId, $rightId, $companyId]);

    // 2. Addresses
    $stmt = $DB->prepare("
        UPDATE crm_addresses 
        SET account_id = ?, updated_at = NOW()
        WHERE account_id = ? AND company_id = ?
    ");
    $stmt->execute([$leftId, $rightId, $companyId]);

    // 3. Interactions
    $stmt = $DB->prepare("
        UPDATE crm_interactions 
        SET account_id = ?
        WHERE account_id = ? AND company_id = ?
    ");
    $stmt->execute([$leftId, $rightId, $companyId]);

    // 4. Compliance docs
    $stmt = $DB->prepare("
        UPDATE crm_compliance_docs 
        SET account_id = ?, updated_at = NOW()
        WHERE account_id = ? AND company_id = ?
    ");
    $stmt->execute([$leftId, $rightId, $companyId]);

    // 5. Quotes: repoint customer_id
    $stmt = $DB->prepare("
        UPDATE quotes 
        SET customer_id = ?, updated_at = NOW()
        WHERE customer_id = ? AND company_id = ?
    ");
    $stmt->execute([$leftId, $rightId, $companyId]);

    // 6. Invoices: repoint customer_id
    $stmt = $DB->prepare("
        UPDATE invoices 
        SET customer_id = ?, updated_at = NOW()
        WHERE customer_id = ? AND company_id = ?
    ");
    $stmt->execute([$leftId, $rightId, $companyId]);

    // 7. Emails: repoint account_id
    $stmt = $DB->prepare("
        UPDATE emails 
        SET account_id = ?, folder = folder
        WHERE account_id = ? AND company_id = ?
    ");
    $stmt->execute([$leftId, $rightId, $companyId]);

    // 5. Tags (merge without duplicates)
    $stmt = $DB->prepare("
        INSERT IGNORE INTO crm_account_tags (account_id, tag_id)
        SELECT ?, tag_id FROM crm_account_tags WHERE account_id = ?
    ");
    $stmt->execute([$leftId, $rightId]);

    // Delete old tags from right account
    $stmt = $DB->prepare("DELETE FROM crm_account_tags WHERE account_id = ?");
    $stmt->execute([$rightId]);

    // 6. Delete the right account
    $stmt = $DB->prepare("
        DELETE FROM crm_accounts 
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$rightId, $companyId]);

    // Mark candidate as resolved
    if ($candidateId) {
        $stmt = $DB->prepare("
            UPDATE crm_merge_candidates 
            SET resolved = 1 
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$candidateId, $companyId]);
    }

    // Also resolve any other candidates involving these accounts
    $stmt = $DB->prepare("
        UPDATE crm_merge_candidates 
        SET resolved = 1 
        WHERE company_id = ? AND (left_id = ? OR right_id = ? OR left_id = ? OR right_id = ?)
    ");
    $stmt->execute([$companyId, $leftId, $leftId, $rightId, $rightId]);

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, created_at)
        VALUES (?, ?, 'merge', 'crm_account', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        $leftId,
        json_encode([
            'merged_from' => $rightAccount['name'],
            'merged_from_id' => $rightId,
            'kept_account' => $leftAccount['name']
        ])
    ]);

    $DB->commit();

    echo json_encode([
        'ok' => true,
        'winner_id' => $leftId,
        'merged_from_id' => $rightId
    ]);

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log("Dedupe merge error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}