<?php
// /qi/ajax/get_email_log.php
// Fetch email log entries for a given document
// Accepts JSON body: {doc_type: 'invoice'|'quote', doc_id: int}

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

// Only allow company users to view logs
$companyId = $_SESSION['company_id'] ?? 0;
if (!$companyId) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$docType = isset($input['doc_type']) ? strtolower(trim($input['doc_type'])) : '';
$docId   = isset($input['doc_id']) ? (int)$input['doc_id'] : 0;

if (!$docType || !$docId) {
    echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
    exit;
}

// Validate doc_type
if (!in_array($docType, ['invoice', 'quote'])) {
    echo json_encode(['ok' => false, 'error' => 'Invalid doc type']);
    exit;
}

try {
    // Check if qi_email_log table exists
    $hasLog = $DB->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qi_email_log'"
    )->fetchColumn();
    if (!$hasLog) {
        echo json_encode(['ok' => true, 'data' => []]);
        exit;
    }
    $stmt = $DB->prepare(
        "SELECT id, recipient, subject, created_at " .
        "FROM qi_email_log " .
        "WHERE company_id = ? AND doc_type = ? AND doc_id = ? " .
        "ORDER BY created_at DESC"
    );
    $stmt->execute([$companyId, $docType, $docId]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($logs as &$log) {
        // Format date/time for display
        $log['created_at'] = date('d M Y H:i', strtotime($log['created_at']));
    }
    echo json_encode(['ok' => true, 'data' => $logs]);
} catch (Exception $e) {
    error_log('Get email log error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Unable to fetch log']);
}