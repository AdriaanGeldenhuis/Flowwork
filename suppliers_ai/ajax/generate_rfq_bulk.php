<?php
// /suppliers_ai/ajax/generate_rfq_bulk.php
// Creates one RFQ (purchase order) per selected supplier candidate.  This endpoint
// builds on generate_rfq.php but will iterate over each candidate ID and
// produce a separate draft purchase order.  It enforces the maximum number
// of RFQ recipients as defined in ai_settings.rfq_max_recipients.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

// Expect JSON input with query_id and candidate_ids array
$input = json_decode(file_get_contents('php://input'), true);
$queryId      = $input['query_id'] ?? null;
$candidateIds = is_array($input['candidate_ids'] ?? null) ? $input['candidate_ids'] : [];

if (!$queryId || empty($candidateIds)) {
    echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
    exit;
}

// Fetch settings to determine max recipients
$stmt = $DB->prepare("SELECT rfq_max_recipients FROM ai_settings WHERE company_id = ?");
$stmt->execute([$companyId]);
$settings      = $stmt->fetch();
$maxRecipients = $settings['rfq_max_recipients'] ?? 8;

if (count($candidateIds) > $maxRecipients) {
    echo json_encode(['ok' => false, 'error' => "Max $maxRecipients suppliers allowed per bulk RFQ"]);
    exit;
}

// Fetch candidate details
$placeholders = implode(',', array_fill(0, count($candidateIds), '?'));
$stmt = $DB->prepare(
    "SELECT * FROM ai_candidates WHERE company_id = ? AND query_id = ? AND id IN ($placeholders)"
);
$stmt->execute(array_merge([$companyId, $queryId], $candidateIds));
$candidates = $stmt->fetchAll();

if (empty($candidates)) {
    echo json_encode(['ok' => false, 'error' => 'No valid candidates found']);
    exit;
}

// Ensure procurement module is available
$tableCheck = $DB->query("SHOW TABLES LIKE 'purchase_orders'")->fetch();
if (!$tableCheck) {
    echo json_encode(['ok' => false, 'error' => 'Procurement module not available']);
    exit;
}

$DB->beginTransaction();

try {
    $rfqIds = [];
    foreach ($candidates as $c) {
        // Determine supplier account ID
        $accountId = $c['account_id'] ?? null;
        if (!$accountId) {
            // Create CRM account for this supplier if not linked yet
            $stmtAcc = $DB->prepare(
                "INSERT INTO crm_accounts (company_id, type, name, phone, email, status, created_by, created_at)
                 VALUES (?, 'supplier', ?, ?, ?, 'active', ?, NOW())"
            );
            $stmtAcc->execute([
                $companyId,
                $c['name'],
                $c['phone'],
                $c['email'],
                $userId
            ]);
            $accountId = $DB->lastInsertId();
            // Save account_id back to candidate for future reference
            $stmtUpd = $DB->prepare("UPDATE ai_candidates SET account_id = ? WHERE id = ?");
            $stmtUpd->execute([$accountId, $c['id']]);
        }
        // Generate unique PO number
        // Use date prefix plus a random suffix to minimise collisions across bulk operations
        $poNumber = 'RFQ-' . date('Ymd') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
        // Insert purchase order (draft RFQ)
        $stmtPO = $DB->prepare(
            "INSERT INTO purchase_orders (company_id, po_number, supplier_id, total, status, created_at)
             VALUES (?, ?, ?, 0.00, 'draft', NOW())"
        );
        $stmtPO->execute([$companyId, $poNumber, $accountId]);
        $rfqId = $DB->lastInsertId();
        $rfqIds[] = $rfqId;
        // Log AI action
        $stmtAct = $DB->prepare(
            "INSERT INTO ai_actions (company_id, query_id, candidate_id, action, details_json, user_id, created_at)
             VALUES (?, ?, ?, 'rfq_bulk', ?, ?, NOW())"
        );
        $stmtAct->execute([
            $companyId,
            $queryId,
            $c['id'],
            json_encode(['rfq_id' => $rfqId, 'po_number' => $poNumber]),
            $userId
        ]);
    }
    // Audit log one entry summarising the bulk operation
    $stmtAudit = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
         VALUES (?, ?, 'ai_rfq_bulk_generated', ?, ?, NOW())"
    );
    $stmtAudit->execute([
        $companyId,
        $userId,
        json_encode(['rfq_ids' => $rfqIds, 'recipients' => count($rfqIds)]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    $DB->commit();
    echo json_encode(['ok' => true, 'rfq_ids' => $rfqIds]);
} catch (Exception $e) {
    $DB->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}