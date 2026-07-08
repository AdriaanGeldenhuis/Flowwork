<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
header('Content-Type: application/json');

require_once __DIR__ . '/../permissions.php';
requireRoles(['viewer','bookkeeper','admin']);

try {
  $companyId = (int)($_SESSION['company_id'] ?? 0);
  if (!$companyId) throw new RuntimeException('No company');

  $q = trim((string)($_GET['q'] ?? ''));
  $status = trim((string)($_GET['status'] ?? ''));
  $dateFrom = (string)($_GET['date_from'] ?? '');
  $dateTo = (string)($_GET['date_to'] ?? '');
  $page = max(1, (int)($_GET['page'] ?? 1));
  $limit = min(100, max(1, (int)($_GET['limit'] ?? 25)));
  $offset = ($page - 1) * $limit;

  $where = ['i.company_id = ?', 'i.deleted_at IS NULL'];
  $params = [$companyId];

  if ($q !== '') {
    $where[] = '(i.invoice_number LIKE ? OR ca.name LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
  }
  if ($status === 'overdue') {
    // 'overdue' is a DERIVED state, never stored (invoices.status is never
    // written as 'overdue' anywhere). Mirror the dashboard KPI's derivation
    // exactly (finances/ar/index.php): past due date, outstanding balance, and
    // a still-collectable status — the whitelist deliberately excludes
    // written_off/refunded/uncollectible so Reminders doesn't chase them.
    $where[] = "i.due_date < CURDATE() AND i.balance_due > 0 AND i.status IN ('sent','viewed','part-paid','overdue')";
  } elseif ($status !== '') {
    $where[] = 'i.status = ?';
    $params[] = $status;
  }
  if ($dateFrom !== '') {
    $where[] = 'i.issue_date >= ?';
    $params[] = $dateFrom;
  }
  if ($dateTo !== '') {
    $where[] = 'i.issue_date <= ?';
    $params[] = $dateTo;
  }

  $sql = 'SELECT i.id, i.invoice_number, i.issue_date, i.due_date, i.status, i.total, i.balance_due, ca.name AS customer_name
          FROM invoices i
          JOIN crm_accounts ca ON ca.id = i.customer_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY i.issue_date DESC, i.id DESC
          LIMIT ? OFFSET ?';

  $params2 = array_merge($params, [$limit, $offset]);
  $stmt = $DB->prepare($sql);
  $stmt->execute($params2);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['ok'=>true, 'data'=>['rows'=>$rows]]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
