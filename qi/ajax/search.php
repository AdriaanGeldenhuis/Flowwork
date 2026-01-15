<?php
// /qi/ajax/search.php
// Provides paginated search for quotes, invoices, recurring schedules and credit notes.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

// Current company context
$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// Parse and sanitise inputs
$type = strtolower(trim($_GET['type'] ?? 'quote'));
$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$pageSize = isset($_GET['page_size']) ? intval($_GET['page_size']) : 20;

// Validate pagination
if ($page < 1) $page = 1;
if ($pageSize < 1 || $pageSize > 200) $pageSize = 20;

// Allowed document types
$allowedTypes = ['quote','invoice','recurring','credit'];
if (!in_array($type, $allowedTypes)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid type']);
    exit;
}

$offset = ($page - 1) * $pageSize;

try {
    // Base conditions and parameters
    $rowsQuery = '';
    $countQuery = '';
    $rowsParams = [];
    $countParams = [];

    switch ($type) {
        case 'quote':
            // Build WHERE clause
            $where = 'WHERE q.company_id = ?';
            $params = [$companyId];
            if ($status !== '') {
                $where .= ' AND q.status = ?';
                $params[] = $status;
            }
            if ($q !== '') {
                $where .= ' AND (q.quote_number LIKE ? OR ca.name LIKE ?)';
                $params[] = '%' . $q . '%';
                $params[] = '%' . $q . '%';
            }
            $countQuery = 'SELECT COUNT(*) AS total FROM quotes q LEFT JOIN crm_accounts ca ON q.customer_id = ca.id ' . $where;
            $rowsQuery = 'SELECT q.id, q.quote_number, q.issue_date, q.expiry_date, q.total, q.status, ca.name AS customer_name
                          FROM quotes q
                          LEFT JOIN crm_accounts ca ON q.customer_id = ca.id ' . $where . '
                          ORDER BY q.created_at DESC
                          LIMIT ' . intval($pageSize) . ' OFFSET ' . intval($offset);
            $rowsParams = $params;
            $countParams = $params;
            break;
        case 'invoice':
            $where = 'WHERE i.company_id = ?';
            $params = [$companyId];
            if ($status !== '') {
                $where .= ' AND i.status = ?';
                $params[] = $status;
            }
            if ($q !== '') {
                $where .= ' AND (i.invoice_number LIKE ? OR ca.name LIKE ?)';
                $params[] = '%' . $q . '%';
                $params[] = '%' . $q . '%';
            }
            $countQuery = 'SELECT COUNT(*) AS total FROM invoices i LEFT JOIN crm_accounts ca ON i.customer_id = ca.id ' . $where;
            $rowsQuery = 'SELECT i.id, i.invoice_number, i.issue_date, i.due_date, i.total, i.status, i.balance_due, ca.name AS customer_name
                          FROM invoices i
                          LEFT JOIN crm_accounts ca ON i.customer_id = ca.id ' . $where . '
                          ORDER BY i.created_at DESC
                          LIMIT ' . intval($pageSize) . ' OFFSET ' . intval($offset);
            $rowsParams = $params;
            $countParams = $params;
            break;
        case 'recurring':
            $where = 'WHERE ri.company_id = ?';
            $params = [$companyId];
            if ($status !== '') {
                // Filter by active/inactive
                if ($status === 'active') {
                    $where .= ' AND ri.active = 1';
                } elseif ($status === 'inactive') {
                    $where .= ' AND ri.active = 0';
                }
            }
            if ($q !== '') {
                $where .= ' AND (ri.template_name LIKE ? OR ca.name LIKE ?)';
                $params[] = '%' . $q . '%';
                $params[] = '%' . $q . '%';
            }
            $countQuery = 'SELECT COUNT(*) AS total FROM recurring_invoices ri LEFT JOIN crm_accounts ca ON ri.customer_id = ca.id ' . $where;
            $rowsQuery = 'SELECT ri.id, ri.template_name, ri.interval_count, ri.frequency, ri.next_run_date, ri.active,
                                 ca.name AS customer_name,
                                 CONCAT(ri.interval_count, " ", ri.frequency, IF(ri.interval_count > 1, "s", "")) AS frequency_label,
                                 CASE WHEN ri.active = 1 THEN "active" ELSE "inactive" END AS status
                          FROM recurring_invoices ri
                          LEFT JOIN crm_accounts ca ON ri.customer_id = ca.id ' . $where . '
                          ORDER BY ri.created_at DESC
                          LIMIT ' . intval($pageSize) . ' OFFSET ' . intval($offset);
            $rowsParams = $params;
            $countParams = $params;
            break;
        case 'credit':
            $where = 'WHERE cn.company_id = ?';
            $params = [$companyId];
            if ($status !== '') {
                $where .= ' AND cn.status = ?';
                $params[] = $status;
            }
            if ($q !== '') {
                $where .= ' AND (cn.credit_note_number LIKE ? OR ca.name LIKE ?)';
                $params[] = '%' . $q . '%';
                $params[] = '%' . $q . '%';
            }
            $countQuery = 'SELECT COUNT(*) AS total FROM credit_notes cn LEFT JOIN crm_accounts ca ON cn.customer_id = ca.id ' . $where;
            $rowsQuery = 'SELECT cn.id, cn.credit_note_number, cn.issue_date, cn.total, cn.status, ca.name AS customer_name
                          FROM credit_notes cn
                          LEFT JOIN crm_accounts ca ON cn.customer_id = ca.id ' . $where . '
                          ORDER BY cn.created_at DESC
                          LIMIT ' . intval($pageSize) . ' OFFSET ' . intval($offset);
            $rowsParams = $params;
            $countParams = $params;
            break;
    }

    // Fetch total count
    $stmt = $DB->prepare($countQuery);
    $stmt->execute($countParams);
    $total = (int)$stmt->fetchColumn();

    // Fetch rows
    $stmt = $DB->prepare($rowsQuery);
    $stmt->execute($rowsParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'data' => ['rows' => $rows, 'total' => $total]]);

} catch (Exception $e) {
    error_log('QI search error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Search failed']);
}