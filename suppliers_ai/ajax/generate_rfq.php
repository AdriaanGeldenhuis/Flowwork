<?php
// /suppliers_ai/ajax/generate_rfq.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$queryId = $input['query_id'] ?? null;
$candidateIds = $input['candidate_ids'] ?? [];

if (!$queryId || empty($candidateIds)) {
    echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
    exit;
}

// Check RFQ max recipients setting
$stmt = $DB->prepare("SELECT rfq_max_recipients FROM ai_settings WHERE company_id = ?");
$stmt->execute([$companyId]);
$settings = $stmt->fetch();
$maxRecipients = $settings['rfq_max_recipients'] ?? 8;

if (count($candidateIds) > $maxRecipients) {
    echo json_encode(['ok' => false, 'error' => "Max $maxRecipients suppliers allowed per RFQ"]);
    exit;
}

// Fetch candidates
$placeholders = implode(',', array_fill(0, count($candidateIds), '?'));
$stmt = $DB->prepare("
    SELECT * FROM ai_candidates 
    WHERE company_id = ? AND query_id = ? AND id IN ($placeholders)
");
$stmt->execute(array_merge([$companyId, $queryId], $candidateIds));
$candidates = $stmt->fetchAll();

if (empty($candidates)) {
    echo json_encode(['ok' => false, 'error' => 'No valid candidates found']);
    exit;
}

// Check if purchase_orders table exists (procurement module)
$tableCheck = $DB->query("SHOW TABLES LIKE 'purchase_orders'")->fetch();
if (!$tableCheck) {
    echo json_encode(['ok' => false, 'error' => 'Procurement module not available']);
    exit;
}

$DB->beginTransaction();

try {
    // Create RFQ/PO stub
    $poNumber = 'RFQ-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    $stmt = $DB->prepare("
        INSERT INTO purchase_orders (company_id, po_number, supplier_id, total, status, created_at)
        VALUES (?, ?, ?, 0.00, 'draft', NOW())
    ");
    
    // Use first candidate as primary supplier (can be enhanced)
    $primarySupplierAccountId = $candidates[0]['account_id'] ?? null;
    
    // If no account_id, create placeholder or skip
    if (!$primarySupplierAccountId) {
        // Create account for primary supplier
        $stmt2 = $DB->prepare("
            INSERT INTO crm_accounts (company_id, type, name, phone, email, status, created_by, created_at)
            VALUES (?, 'supplier', ?, ?, ?, 'active', ?, NOW())
        ");
        $stmt2->execute([
            $companyId,
            $candidates[0]['name'],
            $candidates[0]['phone'],
            $candidates[0]['email'],
            $userId
        ]);
        $primarySupplierAccountId = $DB->lastInsertId();
        
        // Update candidate
        $stmt3 = $DB->prepare("UPDATE ai_candidates SET account_id = ? WHERE id = ?");
        $stmt3->execute([$primarySupplierAccountId, $candidates[0]['id']]);
    }
    
    $stmt->execute([$companyId, $poNumber, $primarySupplierAccountId]);
    $rfqId = $DB->lastInsertId();

    // Log actions for all candidates
    foreach ($candidates as $c) {
        $stmt = $DB->prepare("
            INSERT INTO ai_actions (company_id, query_id, candidate_id, action, details_json, user_id, created_at)
            VALUES (?, ?, ?, 'rfq', ?, ?, NOW())
        ");
        $stmt->execute([
            $companyId,
            $queryId,
            $c['id'],
            json_encode(['rfq_id' => $rfqId, 'po_number' => $poNumber]),
            $userId
        ]);
    }

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'ai_rfq_generated', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['rfq_id' => $rfqId, 'po_number' => $poNumber, 'recipients' => count($candidates)]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode(['ok' => true, 'rfq_id' => $rfqId, 'po_number' => $poNumber]);

} catch (Exception $e) {
    $DB->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}