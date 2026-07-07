<?php
// /finances/ajax/journal_list.php
require_once __DIR__ . '/../lib/http.php';
require_method('GET');

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);

header('Content-Type: application/json');

$companyId = (int)($_SESSION['company_id'] ?? 0);
if (!$companyId) { json_error('Not authorised', 403); }

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
            je.reversed_by_journal_id,
            je.reverses_journal_id,
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

    // Distinct module values actually present for this company. The UI uses
    // this to populate the module filter dynamically, so it only ever offers
    // buckets that exist in the data (manual, fin, payroll, bad_debt,
    // vat_settle, vat_adjust, year_end, …) instead of hard-coded options that
    // match no rows and silently return an empty state.
    $modStmt = $DB->prepare("SELECT DISTINCT module FROM journal_entries
                              WHERE company_id = ? AND module IS NOT NULL AND module <> ''
                              ORDER BY module");
    $modStmt->execute([$companyId]);
    $modules = $modStmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'ok'          => true,
        'data'        => $journals,
        'modules'     => $modules,
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
