<?php
// suggestion_hints.php
// API for reading and writing AI hint mappings.  Hints allow users to
// override vendor, GL account or project suggestions for specific
// keys (e.g. vendor names, line descriptions).  Hints are scoped
// per company.  Supported types: 'vendor', 'gl', 'project'.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

// Ensure user is authenticated
if (!isset($_SESSION['company_id']) || !isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];

// GET: fetch hint for a given key/type
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $type = isset($_GET['type']) ? trim($_GET['type']) : '';
    $key  = isset($_GET['key']) ? trim($_GET['key']) : '';
    if (!$type || !$key) {
        echo json_encode(['ok' => false, 'error' => 'type and key required']);
        exit;
    }
    $allowed = ['vendor','gl','project'];
    if (!in_array($type, $allowed)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid type']);
        exit;
    }
    $stmt = $DB->prepare(
        "SELECT hint_value, hits, last_used_at
         FROM ai_hints
         WHERE company_id = ? AND hint_type = ? AND hint_key = ?
         ORDER BY hits DESC, updated_at DESC
         LIMIT 1"
    );
    $stmt->execute([$companyId, $type, strtolower($key)]);
    $row = $stmt->fetch();
    if ($row) {
        // Update hit count and last_used_at
        $upd = $DB->prepare("UPDATE ai_hints SET hits = hits + 1, last_used_at = NOW() WHERE company_id = ? AND hint_type = ? AND hint_key = ?");
        $upd->execute([$companyId, $type, strtolower($key)]);
        echo json_encode(['ok' => true, 'hint' => [ 'value' => $row['hint_value'], 'hits' => (int)$row['hits'], 'last_used_at' => $row['last_used_at'] ]]);
    } else {
        echo json_encode(['ok' => true, 'hint' => null]);
    }
    exit;
}

// POST: save/update hint
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $type = isset($input['type']) ? trim($input['type']) : '';
    $key  = isset($input['key']) ? trim($input['key']) : '';
    $value= isset($input['value']) ? trim($input['value']) : '';
    if (!$type || !$key || !$value) {
        echo json_encode(['ok' => false, 'error' => 'type, key and value required']);
        exit;
    }
    $allowed = ['vendor','gl','project'];
    if (!in_array($type, $allowed)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid type']);
        exit;
    }
    // Insert or update hint
    try {
        // Try update first
        $stmt = $DB->prepare(
            "UPDATE ai_hints SET hint_value = ?, hits = hits + 1, last_used_at = NOW(), updated_at = NOW()
             WHERE company_id = ? AND hint_type = ? AND hint_key = ?"
        );
        $stmt->execute([$value, $companyId, $type, strtolower($key)]);
        if ($stmt->rowCount() === 0) {
            // Insert new
            $ins = $DB->prepare(
                "INSERT INTO ai_hints (company_id, hint_type, hint_key, hint_value, hits, last_used_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 1, NOW(), NOW(), NOW())"
            );
            $ins->execute([$companyId, $type, strtolower($key), $value]);
        }
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unsupported method']);
