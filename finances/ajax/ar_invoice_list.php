<?php
// /finances/ajax/ar_invoice_list.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);

header('Content-Type: application/json');

$companyId = (int)($_SESSION['company_id'] ?? 0);
if (!$companyId) {
    echo json_encode(['ok' => false, 'error' => 'Not authorised']);
    exit;
}

// Statuses that may be posted to the GL from the AR screen (mirrors the per-row
// gate in ar.js and the server gate in ar_sync_invoice.php).
$SYNCABLE = ['sent', 'viewed', 'part-paid', 'paid', 'overdue'];

// Filters (applied server-side so search/status see the WHOLE table, not just
// the first page — the old LIMIT 100 hid every invoice past the hundredth from
// search entirely).
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');

$where  = "WHERE i.company_id = ? AND i.deleted_at IS NULL";
$params = [$companyId];
if ($status !== '') {
    $where .= " AND i.status = ?";
    $params[] = $status;
}
if ($search !== '') {
    $where .= " AND (i.invoice_number LIKE ? OR c.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

try {
    // "Sync All" mode: return every unsynced syncable invoice id for the whole
    // company (ignoring pagination) so the bulk-sync covers rows beyond the
    // current page. IDs only — cheap.
    if (!empty($_GET['unsynced_ids'])) {
        $ph = implode(',', array_fill(0, count($SYNCABLE), '?'));
        $stmt = $DB->prepare(
            "SELECT i.id
               FROM invoices i
              WHERE i.company_id = ? AND i.deleted_at IS NULL
                AND i.journal_id IS NULL AND i.status IN ($ph)
              ORDER BY i.issue_date ASC"
        );
        $stmt->execute(array_merge([$companyId], $SYNCABLE));
        echo json_encode(['ok' => true, 'data' => ['ids' => $stmt->fetchAll(PDO::FETCH_COLUMN)]]);
        exit;
    }

    // Pagination (mirrors journal_list.php)
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 25)));
    $offset  = ($page - 1) * $perPage;

    $countStmt = $DB->prepare(
        "SELECT COUNT(*)
           FROM invoices i
           LEFT JOIN crm_accounts c ON i.customer_id = c.id
         $where"
    );
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));

    $stmt = $DB->prepare("
        SELECT
            i.id,
            i.invoice_number,
            i.customer_id,
            i.issue_date,
            i.due_date,
            i.status,
            i.total,
            i.balance_due,
            i.journal_id,
            c.name as customer_name
        FROM invoices i
        LEFT JOIN crm_accounts c ON i.customer_id = c.id
        $where
        ORDER BY i.issue_date DESC, i.id DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'          => true,
        'data'        => $invoices,
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $totalPages
    ]);

} catch (Exception $e) {
    error_log("AR invoice list error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to load invoices'
    ]);
}
