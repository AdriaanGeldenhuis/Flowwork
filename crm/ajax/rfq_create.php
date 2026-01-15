<?php
// /crm/ajax/rfq_create.php – API endpoint to save a new RFQ stub
// Accepts POST parameters: account_id (supplier), due_date (ISO date) and
// lines (free‑text). If an RFQ table exists, inserts there; otherwise
// records a stub row in ai_actions with action="rfq" and the details.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    // Collect POST fields
    $accountId = isset($_POST['account_id']) ? (int)$_POST['account_id'] : 0;
    $dueDate = isset($_POST['due_date']) ? trim($_POST['due_date']) : '';
    $lines = isset($_POST['lines']) ? trim($_POST['lines']) : '';

    // Validate fields
    if ($accountId <= 0) {
        throw new Exception('Supplier is required');
    }
    if ($dueDate === '') {
        throw new Exception('Due date is required');
    }
    if ($lines === '') {
        throw new Exception('Line items are required');
    }

    // Ensure supplier belongs to the company and is of type supplier
    $stmt = $DB->prepare(
        "SELECT id FROM crm_accounts WHERE id = ? AND company_id = ? AND type = 'supplier'"
    );
    $stmt->execute([$accountId, $companyId]);
    if (!$stmt->fetch()) {
        throw new Exception('Invalid supplier account');
    }

    // Determine if an RFQ table exists
    $tableExists = false;
    $checkStmt = $DB->prepare("SHOW TABLES LIKE 'rfq%' ");
    $checkStmt->execute();
    while ($row = $checkStmt->fetch(PDO::FETCH_NUM)) {
        $tableName = $row[0];
        if (strtolower($tableName) === 'rfqs' || strtolower($tableName) === 'rfq') {
            $tableExists = $tableName;
            break;
        }
    }

    if ($tableExists) {
        // Insert into existing RFQ table. Attempt to guess column names.
        // Check if columns exist: company_id, account_id, due_date, lines, created_by, created_at.
        // Build a dynamic insert based on available columns.
        // Fetch columns for the table
        $colsStmt = $DB->prepare("DESCRIBE `$tableExists`");
        $colsStmt->execute();
        $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
        $insertCols = [];
        $placeholders = [];
        $values = [];
        // Always include company_id, account_id and created_by
        if (in_array('company_id', $cols)) {
            $insertCols[] = 'company_id';
            $placeholders[] = '?';
            $values[] = $companyId;
        }
        if (in_array('account_id', $cols)) {
            $insertCols[] = 'account_id';
            $placeholders[] = '?';
            $values[] = $accountId;
        }
        if (in_array('due_date', $cols)) {
            $insertCols[] = 'due_date';
            $placeholders[] = '?';
            $values[] = $dueDate;
        }
        if (in_array('lines', $cols)) {
            $insertCols[] = 'lines';
            $placeholders[] = '?';
            $values[] = $lines;
        } elseif (in_array('body', $cols)) {
            // Some RFQ tables might name the text column differently
            $insertCols[] = 'body';
            $placeholders[] = '?';
            $values[] = $lines;
        }
        if (in_array('created_by', $cols)) {
            $insertCols[] = 'created_by';
            $placeholders[] = '?';
            $values[] = $userId;
        }
        // Insert row
        $insertSql = "INSERT INTO `$tableExists` (" . implode(',', $insertCols) . ") VALUES (" . implode(',', $placeholders) . ")";
        $insStmt = $DB->prepare($insertSql);
        $insStmt->execute($values);
    } else {
        // Insert a stub into ai_actions with action="rfq"
        $details = json_encode([
            'account_id' => $accountId,
            'due_date' => $dueDate,
            'lines' => $lines
        ]);
        $insertStub = $DB->prepare(
            "INSERT INTO ai_actions (company_id, query_id, candidate_id, action, details_json, user_id) 
             VALUES (?, 0, ?, 'rfq', ?, ?)"
        );
        $insertStub->execute([$companyId, $accountId, $details, $userId]);
    }
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    error_log('RFQ create error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}
