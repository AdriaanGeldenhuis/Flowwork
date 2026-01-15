<?php
// /finances/ap/api/three_way_apply.php – Apply matches between PO, GRN and Bill lines

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';

// HTTP method guard
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    json_error('Error');
}

// CSRF validation
require_once __DIR__ . '/../../lib/Csrf.php';
Csrf::validate();

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);

// Read the JSON body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    json_error('Error');
}

// Expect an array of matches under the "matches" key or treat the whole input as an array of matches
$matches = [];
if (isset($data['matches']) && is_array($data['matches'])) {
    $matches = $data['matches'];
} elseif (isset($data[0])) {
    // Input is an array of matches directly
    $matches = $data;
} else {
    http_response_code(400);
    json_error('Error');
}

if ($companyId <= 0 || $userId <= 0) {
    http_response_code(400);
    json_error('Error');
}

// Prepare the insert statement
$insertSql = "INSERT INTO ap_match_links (company_id, po_line_id, grn_line_id, bill_line_id, qty_matched, created_by) VALUES (?, ?, ?, ?, ?, ?)";
$stmt = $DB->prepare($insertSql);

$inserted = 0;
$errors = [];

try {
    $DB->beginTransaction();
    foreach ($matches as $m) {
        // Each match should be an associative array
        $poLineId  = isset($m['po_line_id']) && $m['po_line_id'] !== '' ? (int)$m['po_line_id'] : null;
        $grnLineId = isset($m['grn_line_id']) && $m['grn_line_id'] !== '' ? (int)$m['grn_line_id'] : null;
        $billLineId = isset($m['bill_line_id']) && $m['bill_line_id'] !== '' ? (int)$m['bill_line_id'] : null;
        $qty        = isset($m['qty']) ? (float)$m['qty'] : 0.0;

        if ($qty <= 0 || ($poLineId === null && $grnLineId === null && $billLineId === null)) {
            // Skip invalid matches (no quantity or no references)
            continue;
        }

        // Insert the match record
        $stmt->execute([
            $companyId,
            $poLineId,
            $grnLineId,
            $billLineId,
            $qty,
            $userId
        ]);
        $inserted++;
    }
    $DB->commit();
    echo json_encode(['ok' => true, 'data' => ['inserted' => $inserted, 'errors' => $errors]]);
} catch (Exception $e) {
    $DB->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to apply matches: ' . $e->getMessage()]);
}