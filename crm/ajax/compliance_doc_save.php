<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$docId = (int)($_POST['doc_id'] ?? 0);
$accountId = (int)($_POST['account_id'] ?? 0);
$typeId = (int)($_POST['type_id'] ?? 0);
$referenceNo = trim($_POST['reference_no'] ?? '');
$expiryDate = trim($_POST['expiry_date'] ?? '');
$notes = trim($_POST['notes'] ?? '');

try {
    if (!$accountId || !$typeId) {
        throw new Exception('Account ID and document type are required');
    }
    
    $filePath = null;
    
    // Handle file upload
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../../uploads/compliance/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $allowedExts = ['pdf', 'jpg', 'jpeg', 'png'];
        
        if (!in_array(strtolower($ext), $allowedExts)) {
            throw new Exception('Invalid file type. Only PDF, JPG, PNG allowed');
        }
        
        if ($_FILES['file']['size'] > 5 * 1024 * 1024) {
            throw new Exception('File too large. Max 5MB');
        }
        
        $fileName = uniqid('comp_') . '.' . $ext;
        $filePath = 'uploads/compliance/' . $fileName;
        
        move_uploaded_file($_FILES['file']['tmp_name'], $uploadDir . $fileName);
    }
    
    // Determine status based on expiry
    $status = 'valid';
    if ($expiryDate) {
        $expiry = strtotime($expiryDate);
        $now = time();
        $thirtyDays = 30 * 24 * 60 * 60;
        
        if ($expiry < $now) {
            $status = 'expired';
        } elseif ($expiry < ($now + $thirtyDays)) {
            $status = 'expiring';
        }
    }
    
    if ($docId > 0) {
        // UPDATE existing
        if ($filePath) {
            // If new file uploaded, update file path too
            $stmt = $DB->prepare("
                UPDATE crm_compliance_docs 
                SET type_id = ?, reference_no = ?, expiry_date = ?, 
                    file_path = ?, notes = ?, status = ?
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([$typeId, $referenceNo, $expiryDate ?: null, $filePath, $notes, $status, $docId, $companyId]);
        } else {
            // No new file, keep existing file
            $stmt = $DB->prepare("
                UPDATE crm_compliance_docs 
                SET type_id = ?, reference_no = ?, expiry_date = ?, 
                    notes = ?, status = ?
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([$typeId, $referenceNo, $expiryDate ?: null, $notes, $status, $docId, $companyId]);
        }
        
        echo json_encode([
            'ok' => true,
            'message' => 'Document updated successfully',
            'doc_id' => $docId
        ]);
    } else {
        // INSERT new
        $stmt = $DB->prepare("
            INSERT INTO crm_compliance_docs 
            (account_id, company_id, type_id, reference_no, expiry_date, file_path, notes, status, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $accountId, 
            $companyId, 
            $typeId, 
            $referenceNo, 
            $expiryDate ?: null, 
            $filePath, 
            $notes, 
            $status, 
            $userId
        ]);
        
        $newId = $DB->lastInsertId();
        
        echo json_encode([
            'ok' => true,
            'message' => 'Document uploaded successfully',
            'doc_id' => $newId
        ]);
    }
    
} catch (Exception $e) {
    error_log('CRM compliance_doc_save error: ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}