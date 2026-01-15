<?php
// /crm/ajax/account_save.php - FINAL FIXED VERSION
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    $DB->beginTransaction();

    $accountId = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $isEdit = $accountId !== null;

    // Validate required fields
    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? 'supplier';
    
    if ($name === '') {
        throw new Exception('Account name is required');
    }

    if (!in_array($type, ['supplier', 'customer'])) {
        throw new Exception('Invalid account type');
    }

    // Normalize phone
    $phone = trim($_POST['phone'] ?? '');
    if ($phone && !preg_match('/^\+/', $phone)) {
        $phone = '+27' . ltrim($phone, '0');
    }

    if ($isEdit) {
        // Check for duplicate name (exclude self)
        $dupStmt = $DB->prepare("
            SELECT id FROM crm_accounts 
            WHERE company_id = ? AND type = ? AND name = ? AND id != ?
        ");
        $dupStmt->execute([$companyId, $type, $name, $accountId]);
        if ($dupStmt->fetch()) {
            throw new Exception('An account with this name already exists');
        }

        // Prepare all values FIRST
        $legalName = trim($_POST['legal_name'] ?? '');
        $regNo = trim($_POST['reg_no'] ?? '');
        $vatNo = trim($_POST['vat_no'] ?? '');
        $emailVal = trim($_POST['email'] ?? '');
        $websiteVal = trim($_POST['website'] ?? '');
        $industryId = !empty($_POST['industry_id']) ? (int)$_POST['industry_id'] : null;
        $regionId = !empty($_POST['region_id']) ? (int)$_POST['region_id'] : null;
        $statusVal = $_POST['status'] ?? 'active';
        $preferredVal = isset($_POST['preferred']) ? 1 : 0;
        $notesVal = trim($_POST['notes'] ?? '');

        // UPDATE with EXACTLY 14 parameters
        $stmt = $DB->prepare("
            UPDATE crm_accounts SET
                name = ?,
                legal_name = ?,
                reg_no = ?,
                vat_no = ?,
                phone = ?,
                email = ?,
                website = ?,
                industry_id = ?,
                region_id = ?,
                status = ?,
                preferred = ?,
                notes = ?,
                updated_at = NOW()
            WHERE id = ? AND company_id = ?
        ");

        $executeParams = [
            $name,                                    // 1
            $legalName ?: null,                       // 2
            $regNo ?: null,                           // 3
            $vatNo ?: null,                           // 4
            $phone ?: null,                           // 5
            $emailVal ?: null,                        // 6
            $websiteVal ?: null,                      // 7
            $industryId,                              // 8
            $regionId,                                // 9
            $statusVal,                               // 10
            $preferredVal,                            // 11
            $notesVal ?: null,                        // 12
            $accountId,                               // 13
            $companyId                                // 14
        ];

        // SAFETY CHECK
        if (count($executeParams) !== 14) {
            throw new Exception('Parameter count mismatch: ' . count($executeParams) . ' instead of 14');
        }

        $stmt->execute($executeParams);

        // Audit log
        $stmt = $DB->prepare("
            INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
            VALUES (?, ?, 'crm_account_update', ?, NOW())
        ");
        $stmt->execute([
            $companyId,
            $userId,
            json_encode(['id' => $accountId, 'name' => $name, 'type' => $type])
        ]);

    } else {
        // Check for duplicate name
        $dupStmt = $DB->prepare("
            SELECT id FROM crm_accounts 
            WHERE company_id = ? AND type = ? AND name = ?
        ");
        $dupStmt->execute([$companyId, $type, $name]);
        if ($dupStmt->fetch()) {
            throw new Exception('An account with this name already exists');
        }

        // INSERT new account
        $stmt = $DB->prepare("
            INSERT INTO crm_accounts (
                company_id, type, name, legal_name, reg_no, vat_no,
                phone, email, website, industry_id, region_id, status,
                preferred, notes, created_by, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, NOW()
            )
        ");

        $stmt->execute([
            $companyId,
            $type,
            $name,
            trim($_POST['legal_name'] ?? '') ?: null,
            trim($_POST['reg_no'] ?? '') ?: null,
            trim($_POST['vat_no'] ?? '') ?: null,
            $phone ?: null,
            trim($_POST['email'] ?? '') ?: null,
            trim($_POST['website'] ?? '') ?: null,
            !empty($_POST['industry_id']) ? (int)$_POST['industry_id'] : null,
            !empty($_POST['region_id']) ? (int)$_POST['region_id'] : null,
            $_POST['status'] ?? 'active',
            isset($_POST['preferred']) ? 1 : 0,
            trim($_POST['notes'] ?? '') ?: null,
            $userId
        ]);

        $accountId = $DB->lastInsertId();

        // Insert primary contact (if provided)
        $contactFirstName = trim($_POST['contact_first_name'] ?? '');
        $contactLastName = trim($_POST['contact_last_name'] ?? '');
        
        if ($contactFirstName || $contactLastName) {
            $contactPhone = trim($_POST['contact_phone'] ?? '');
            if ($contactPhone && !preg_match('/^\+/', $contactPhone)) {
                $contactPhone = '+27' . ltrim($contactPhone, '0');
            }

            $stmt = $DB->prepare("
                INSERT INTO crm_contacts (
                    company_id, account_id, first_name, last_name, role_title,
                    phone, email, is_primary, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
            ");

            $stmt->execute([
                $companyId,
                $accountId,
                $contactFirstName ?: null,
                $contactLastName ?: null,
                trim($_POST['contact_role_title'] ?? '') ?: null,
                $contactPhone ?: null,
                trim($_POST['contact_email'] ?? '') ?: null,
                $userId
            ]);
        }

        // Insert primary address (if provided)
        $addressLine1 = trim($_POST['address_line1'] ?? '');
        
        if ($addressLine1) {
            $stmt = $DB->prepare("
                INSERT INTO crm_addresses (
                    company_id, account_id, type, line1, line2,
                    city, region, postal_code, country, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $companyId,
                $accountId,
                $_POST['address_type'] ?? 'head_office',
                $addressLine1,
                trim($_POST['address_line2'] ?? '') ?: null,
                trim($_POST['address_city'] ?? '') ?: null,
                trim($_POST['address_region'] ?? '') ?: null,
                trim($_POST['address_postal_code'] ?? '') ?: null,
                strtoupper(trim($_POST['address_country'] ?? 'ZA'))
            ]);
        }

        // Audit log
        $stmt = $DB->prepare("
            INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
            VALUES (?, ?, 'crm_account_create', ?, NOW())
        ");
        $stmt->execute([
            $companyId,
            $userId,
            json_encode(['id' => $accountId, 'name' => $name, 'type' => $type])
        ]);
    }

    $DB->commit();

    echo json_encode(['ok' => true, 'account_id' => $accountId]);

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log("CRM account_save error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}