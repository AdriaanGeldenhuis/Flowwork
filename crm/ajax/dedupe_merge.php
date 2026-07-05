<?php
// /crm/ajax/dedupe_merge.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/_helpers.php';

crm_require_min_role('admin');

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

    // Verify both accounts exist and belong to company (fetch the mergeable
    // columns up front — the old code re-queried once per field)
    $fields = [
        'name', 'legal_name', 'reg_no', 'vat_no', 'email', 'phone',
        'website', 'industry_id', 'region_id', 'status', 'notes'
    ];
    $stmt = $DB->prepare("SELECT id, " . implode(', ', $fields) . " FROM crm_accounts WHERE id = ? AND company_id = ?");

    $stmt->execute([$leftId, $companyId]);
    $leftAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$leftAccount) {
        throw new Exception('Left account not found');
    }

    $stmt->execute([$rightId, $companyId]);
    $rightAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rightAccount) {
        throw new Exception('Right account not found');
    }

    // Build merged account data based on selected fields
    $mergedData = [];
    foreach ($fields as $field) {
        $selectedSide = $selectedFields[$field] ?? 'left';
        $mergedData[$field] = $selectedSide === 'left' ? $leftAccount[$field] : $rightAccount[$field];
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

    // 4b. Opportunities (previously missed — merges orphaned these)
    $stmt = $DB->prepare("
        UPDATE crm_opportunities
        SET account_id = ?, updated_at = NOW()
        WHERE account_id = ? AND company_id = ?
    ");
    $stmt->execute([$leftId, $rightId, $companyId]);

    // 4c. Purchase orders reference suppliers directly
    $stmt = $DB->prepare("
        UPDATE purchase_orders
        SET supplier_id = ?
        WHERE supplier_id = ? AND company_id = ?
    ");
    $stmt->execute([$leftId, $rightId, $companyId]);

    // 4d. Projects carry the CRM customer in client_id (opportunity_convert)
    $stmt = $DB->prepare("
        UPDATE projects
        SET client_id = ?
        WHERE client_id = ? AND company_id = ?
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

    // 7. Emails are associated to CRM accounts via email_links only —
    //    emails.account_id is the MAIL account id (email_accounts), so the
    //    old "repoint emails.account_id" step here silently moved a whole
    //    mailbox between mail accounts and was removed.

    // 7b. Polymorphic email links (linked_type is the account's type string).
    //     UPDATE IGNORE skips rows that would duplicate an existing link to
    //     the winner; the DELETE clears those leftovers.
    $stmt = $DB->prepare("
        UPDATE IGNORE email_links
        SET linked_id = ?
        WHERE linked_id = ? AND company_id = ? AND linked_type IN ('supplier', 'customer')
    ");
    $stmt->execute([$leftId, $rightId, $companyId]);
    $stmt = $DB->prepare("
        DELETE FROM email_links
        WHERE linked_id = ? AND company_id = ? AND linked_type IN ('supplier', 'customer')
    ");
    $stmt->execute([$rightId, $companyId]);

    // 5. Tags (merge without duplicates)
    $stmt = $DB->prepare("
        INSERT IGNORE INTO crm_account_tags (account_id, tag_id)
        SELECT ?, tag_id FROM crm_account_tags WHERE account_id = ?
    ");
    $stmt->execute([$leftId, $rightId]);

    // Delete old tags from right account
    $stmt = $DB->prepare("DELETE FROM crm_account_tags WHERE account_id = ?");
    $stmt->execute([$rightId]);

    // 6. Soft-delete the losing account (audit trail + restorable via the
    //    "Deleted only" filter; a hard DELETE also broke FK'd history rows)
    $stmt = $DB->prepare("
        UPDATE crm_accounts
        SET deleted_at = NOW(), updated_at = NOW()
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

} catch (Throwable $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log("Dedupe merge error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => crm_public_error($e)]);
}