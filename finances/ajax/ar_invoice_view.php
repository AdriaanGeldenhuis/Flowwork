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

  $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
  if ($id <= 0) throw new InvalidArgumentException('Invalid id');

  $stmt = $DB->prepare('SELECT i.*, ca.name AS customer_name
                        FROM invoices i
                        JOIN crm_accounts ca ON ca.id = i.customer_id
                        WHERE i.company_id = ? AND i.id = ?
                        LIMIT 1');
  $stmt->execute([$companyId, $id]);
  $inv = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$inv) throw new RuntimeException('Invoice not found');

  $stmtL = $DB->prepare('SELECT item_description, quantity, unit, unit_price, tax_rate, line_total
                         FROM invoice_lines
                         WHERE invoice_id = ?
                         ORDER BY sort_order ASC, id ASC');
  $stmtL->execute([$id]);
  $lines = $stmtL->fetchAll(PDO::FETCH_ASSOC);

  $payload = [
    'id' => (int)$inv['id'],
    'invoice_number' => $inv['invoice_number'],
    'customer_name' => $inv['customer_name'],
    'issue_date' => $inv['issue_date'],
    'due_date' => $inv['due_date'],
    'status' => $inv['status'],
    'subtotal' => $inv['subtotal'],
    'discount' => $inv['discount'],
    'tax' => $inv['tax'],
    'total' => $inv['total'],
    'balance_due' => $inv['balance_due'],
    'journal_id' => $inv['journal_id'],
    'lines' => $lines
  ];

  echo json_encode(['ok'=>true, 'data'=>$payload]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
