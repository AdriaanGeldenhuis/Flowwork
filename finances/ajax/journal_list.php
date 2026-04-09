<?php
// /finances/ajax/journal_list.php
require_once __DIR__ . '/../lib/http.php';
require_method('GET');

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

// Filters
$dateFrom = $_GET['dateFrom'] ?? '';
$dateTo   = $_GET['dateTo'] ?? '';
$module   = $_GET['module'] ?? '';
$status   = $_GET['status'] ?? '';
$search   = $_GET['search'] ?? '';

// Pagination
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int)($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;

try {
    $where  = "WHERE je.company_id = ?";
    $params = [$companyId];

    if ($dateFrom) {
        $where .= " AND je.entry_date >= ?";
        $params[] = $dateFrom;
    }

    if ($dateTo) {
        $where .= " AND je.entry_date <= ?";
        $params[] = $dateTo;
    }

    if ($module) {
        $where .= " AND je.module = ?";
        $params[] = $module;
    }

    if ($status) {
        $where .= " AND je.status = ?";
        $params[] = $status;
    }

    if ($search) {
        $where .= " AND (je.description LIKE ? OR je.reference LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    // Count total
    $countStmt = $DB->prepare("SELECT COUNT(*) FROM journal_entries je $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));

    // Fetch page
    $sql = "
        SELECT
            je.id AS journal_id,
            je.entry_date,
            je.reference,
            je.description AS memo,
            je.module,
            je.ref_type,
            je.ref_id,
            je.status,
            je.created_at,
            je.created_by,
            u.first_name AS created_first_name,
            u.last_name AS created_last_name,
            (SELECT COUNT(*) FROM journal_lines WHERE journal_id = je.id) AS line_count,
            CAST(COALESCE((SELECT SUM(debit) FROM journal_lines WHERE journal_id = je.id), 0) * 100 AS SIGNED) AS total_debits_cents
        FROM journal_entries je
        LEFT JOIN users u ON je.created_by = u.id
        $where
        ORDER BY je.entry_date DESC, je.id DESC
        LIMIT $perPage OFFSET $offset
    ";

    $stmt = $DB->prepare($sql);
    $stmt->execute($params);
    $journals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'          => true,
        'data'        => $journals,
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $totalPages
    ]);

} catch (Exception $e) {
    error_log("Journal list error: " . $e->getMessage());
    echo json_encode([
        'ok'    => false,
        'error' => 'Failed to load journal entries'
    ]);
}
