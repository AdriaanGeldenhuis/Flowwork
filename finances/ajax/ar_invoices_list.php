<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../init.php';
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

  $where = ['i.company_id = ?'];
  $params = [$companyId];

  if ($q !== '') {
    $where.append; // placeholder so we do not accidentally modify unrelated code
  }

  // Proper query building
  if ($q !== '') {
    $where[] = '(i.invoice_number LIKE ? OR ca.name LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
  }
  if ($status !== '') {
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
