<?php
// /crm/ajax/account_linked.php
// Returns a JSON object with linked quotes, invoices and RFQs for a given account.
// Queries are read‑only; the endpoint ensures the account belongs to the current company.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$accountId = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;

try {
    if (!$accountId) {
        throw new Exception('account_id is required');
    }
    // Verify account belongs to company
    $stmt = $DB->prepare("SELECT id FROM crm_accounts WHERE id = ? AND company_id = ?");
    $stmt->execute([$accountId, $companyId]);
    if (!$stmt->fetch()) {
        throw new Exception('Account not found');
    }

    $quotes = [];
    // Fetch quotes if table exists
    try {
        // Test existence by performing a no‑op select
        $DB->query("SELECT 1 FROM quotes LIMIT 0");
        $qStmt = $DB->prepare("SELECT id, quote_number AS number, status, created_at FROM quotes WHERE company_id = ? AND customer_id = ? ORDER BY created_at DESC LIMIT 50");
        $qStmt->execute([$companyId, $accountId]);
        $quotes = $qStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // quotes table does not exist or other error; leave empty
        $quotes = [];
    }

    $invoices = [];
    // Fetch invoices if table exists
    try {
        $DB->query("SELECT 1 FROM invoices LIMIT 0");
        $iStmt = $DB->prepare("SELECT id, invoice_number AS number, status, created_at FROM invoices WHERE company_id = ? AND customer_id = ? ORDER BY created_at DESC LIMIT 50");
        $iStmt->execute([$companyId, $accountId]);
        $invoices = $iStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // invoices table missing; leave empty
        $invoices = [];
    }

    $rfqs = [];
    // Determine if an RFQ table exists
    $rfqTable = null;
    try {
        $tables = $DB->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $tbl) {
            $lt = strtolower($tbl);
            if ($lt === 'rfqs' || $lt === 'rfq') {
                $rfqTable = $tbl;
                break;
            }
        }
    } catch (Exception $e) {
        $rfqTable = null;
    }

    if ($rfqTable) {
        try {
            // Fetch column definitions to determine available fields
            $colsStmt = $DB->prepare("DESCRIBE `$rfqTable`");
            $colsStmt->execute();
            $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
            $fields = [];
            if (in_array('id', $cols)) $fields[] = 'id';
            // Try to select number if present
            if (in_array('rfq_number', $cols)) {
                $fields[] = 'rfq_number AS number';
            } elseif (in_array('number', $cols)) {
                $fields[] = 'number';
            }
            if (in_array('status', $cols)) $fields[] = 'status';
            if (in_array('due_date', $cols)) $fields[] = 'due_date';
            if (in_array('lines', $cols)) $fields[] = 'lines';
            if (in_array('created_at', $cols)) $fields[] = 'created_at';
            $select = implode(',', $fields);
            if (!$select) {
                // If no fields identified, at least select id
                $select = 'id';
            }
            $rqStmt = $DB->prepare("SELECT $select FROM `$rfqTable` WHERE company_id = ? AND account_id = ? ORDER BY created_at DESC LIMIT 50");
            $rqStmt->execute([$companyId, $accountId]);
            $rows = $rqStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $rfq = [];
                if (isset($row['id'])) $rfq['id'] = (int)$row['id'];
                if (isset($row['number'])) $rfq['number'] = $row['number'];
                if (isset($row['status'])) $rfq['status'] = $row['status'];
                if (isset($row['due_date'])) $rfq['due_date'] = $row['due_date'];
                if (isset($row['lines'])) $rfq['lines'] = $row['lines'];
                if (isset($row['created_at'])) $rfq['created_at'] = $row['created_at'];
                $rfqs[] = $rfq;
            }
        } catch (Exception $e) {
            // If query fails, treat as no RFQs
            $rfqs = [];
        }
    } else {
        // Fall back to stubbed RFQs stored in ai_actions
        try {
            $aStmt = $DB->prepare("SELECT id, details_json, created_at FROM ai_actions WHERE company_id = ? AND action = 'rfq' AND candidate_id = ? ORDER BY id DESC LIMIT 50");
            $aStmt->execute([$companyId, $accountId]);
            $rows = $aStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $details = json_decode($row['details_json'], true);
                $rfq = [
                    'id' => null,
                    'stub_id' => (int)$row['id'],
                    'due_date' => $details['due_date'] ?? null,
                    'lines' => $details['lines'] ?? null,
                    'created_at' => $row['created_at'] ?? null
                ];
                $rfqs[] = $rfq;
            }
        } catch (Exception $e) {
            $rfqs = [];
        }
    }

    echo json_encode([
        'quotes' => $quotes,
        'invoices' => $invoices,
        'rfqs' => $rfqs
    ]);
} catch (Exception $e) {
    error_log('CRM account_linked error: ' . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}