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

  $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
  if ($id <= 0) throw new InvalidArgumentException('Invalid id');

  // Fetch invoice with customer info
  $stmt = $DB->prepare('SELECT i.*, ca.name AS customer_name, ca.vat_no AS customer_vat,
                                ca.phone AS customer_phone, ca.email AS customer_email
                        FROM invoices i
                        JOIN crm_accounts ca ON ca.id = i.customer_id
                        WHERE i.company_id = ? AND i.id = ?
                        LIMIT 1');
  $stmt->execute([$companyId, $id]);
  $inv = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$inv) throw new RuntimeException('Invoice not found');

  // Fetch invoice lines
  $stmtL = $DB->prepare('SELECT item_description, quantity, unit, unit_price, tax_rate, line_total
                         FROM invoice_lines
                         WHERE invoice_id = ?
                         ORDER BY sort_order ASC, id ASC');
  $stmtL->execute([$id]);
  $lines = $stmtL->fetchAll(PDO::FETCH_ASSOC);

  // Fetch company details
  $stmtC = $DB->prepare('SELECT name, vat_number, tax_number, reg_number, phone, email, website,
                                 logo_url, address_line1, address_line2, city, region, postal,
                                 bank_name, bank_account_number, bank_branch_code, invoice_footer_text
                          FROM companies WHERE id = ? LIMIT 1');
  $stmtC->execute([$companyId]);
  $company = $stmtC->fetch(PDO::FETCH_ASSOC);

  // Fetch customer billing address (prefer billing, fallback to any)
  $stmtA = $DB->prepare("SELECT line1, line2, city, region, postal_code, country
                          FROM crm_addresses
                          WHERE account_id = ? AND company_id = ?
                          ORDER BY FIELD(type, 'billing', 'head_office', 'site', 'shipping') ASC
                          LIMIT 1");
  $stmtA->execute([$inv['customer_id'], $companyId]);
  $customerAddress = $stmtA->fetch(PDO::FETCH_ASSOC) ?: null;

  $payload = [
    'id' => (int)$inv['id'],
    'invoice_number' => $inv['invoice_number'],
    'customer_name' => $inv['customer_name'],
    'customer_vat' => $inv['customer_vat'],
    'customer_phone' => $inv['customer_phone'],
    'customer_email' => $inv['customer_email'],
    'customer_address' => $customerAddress,
    'issue_date' => $inv['issue_date'],
    'due_date' => $inv['due_date'],
    'status' => $inv['status'],
    'subtotal' => $inv['subtotal'],
    'discount' => $inv['discount'],
    'tax' => $inv['tax'],
    'total' => $inv['total'],
    'balance_due' => $inv['balance_due'],
    'currency' => $inv['currency'] ?? 'ZAR',
    'terms' => $inv['terms'],
    'notes' => $inv['notes'],
    'journal_id' => $inv['journal_id'],
    'lines' => $lines,
    'company' => $company
  ];

  echo json_encode(['ok'=>true, 'data'=>$payload]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
