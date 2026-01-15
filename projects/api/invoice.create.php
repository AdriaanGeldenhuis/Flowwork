<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$projectId = (int)($_POST['project_id'] ?? 0);
$clientId = (int)($_POST['client_id'] ?? 0);
$issueDate = $_POST['issue_date'] ?? date('Y-m-d');
$dueDate = $_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days'));
$notes = trim($_POST['notes'] ?? '');
$timesheetIds = json_decode($_POST['timesheet_ids'] ?? '[]', true);
$costIds = json_decode($_POST['cost_ids'] ?? '[]', true);

if (!$projectId) respond_error('Project ID required');
if (!$clientId) respond_error('Client ID required');

require_project_role($projectId, 'manager');

try {
    $DB->beginTransaction();
    
    // Generate invoice number
    $stmt = $DB->prepare("SELECT MAX(CAST(SUBSTRING(invoice_number, 5) AS UNSIGNED)) FROM invoices WHERE company_id = ?");
    $stmt->execute([$COMPANY_ID]);
    $lastNum = (int)$stmt->fetchColumn();
    $invoiceNumber = 'INV-' . str_pad($lastNum + 1, 5, '0', STR_PAD_LEFT);
    
    // Create invoice
    $stmt = $DB->prepare("
        INSERT INTO invoices (
            company_id, project_id, client_id, invoice_number, 
            status, issue_date, due_date, notes, created_by, created_at
        ) VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$COMPANY_ID, $projectId, $clientId, $invoiceNumber, $issueDate, $dueDate, $notes, $USER_ID]);
    
    $invoiceId = $DB->lastInsertId();
    
    $totalCents = 0;
    
    // Add timesheet lines
    if (!empty($timesheetIds)) {
        $placeholders = implode(',', array_fill(0, count($timesheetIds), '?'));
        $stmt = $DB->prepare("
            SELECT t.*, u.first_name, u.last_name, pm.billable_rate
            FROM timesheets t
            JOIN users u ON t.user_id = u.id
            LEFT JOIN project_members pm ON pm.project_id = t.project_id AND pm.user_id = t.user_id
            WHERE t.id IN ($placeholders) AND t.company_id = ? AND t.billable = 1
        ");
        $stmt->execute(array_merge($timesheetIds, [$COMPANY_ID]));
        $timesheets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmtLine = $DB->prepare("
            INSERT INTO invoice_lines (invoice_id, description, qty, unit_price_cents, tax_code, link_item_id)
            VALUES (?, ?, ?, ?, 'standard', ?)
        ");
        
        foreach ($timesheets as $ts) {
            $rate = $ts['billable_rate'] ?? 500; // Default R500/hr
            $description = sprintf(
                'Time: %s - %s (%s hours)',
                $ts['first_name'] . ' ' . $ts['last_name'],
                $ts['date'],
                $ts['hours']
            );
            if ($ts['note']) $description .= ' - ' . $ts['note'];
            
            $unitPriceCents = (int)($rate * 100);
            $stmtLine->execute([$invoiceId, $description, $ts['hours'], $unitPriceCents, $ts['item_id']]);
            
            $totalCents += (int)($ts['hours'] * $unitPriceCents);
        }
    }
    
    // Add cost lines
    if (!empty($costIds)) {
        $placeholders = implode(',', array_fill(0, count($costIds), '?'));
        $stmt = $DB->prepare("
            SELECT * FROM cost_items
            WHERE id IN ($placeholders) AND company_id = ?
        ");
        $stmt->execute(array_merge($costIds, [$COMPANY_ID]));
        $costs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmtLine = $DB->prepare("
            INSERT INTO invoice_lines (invoice_id, description, qty, unit_price_cents, tax_code, link_item_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($costs as $cost) {
            $unitPriceCents = (int)($cost['unit_cost'] * 100);
            $stmtLine->execute([
                $invoiceId, 
                $cost['description'], 
                $cost['qty'], 
                $unitPriceCents, 
                $cost['tax_code'], 
                $cost['item_id']
            ]);
            
            $totalCents += (int)($cost['qty'] * $unitPriceCents);
        }
    }
    
    // Update invoice total
    $stmt = $DB->prepare("UPDATE invoices SET total_cents = ? WHERE id = ?");
    $stmt->execute([$totalCents, $invoiceId]);
    
    $DB->commit();
    
    respond_ok([
        'invoice_id' => $invoiceId,
        'invoice_number' => $invoiceNumber,
        'total' => $totalCents / 100
    ]);
    
} catch (Exception $e) {
    $DB->rollBack();
    error_log("Invoice create error: " . $e->getMessage());
    respond_error('Failed to create invoice', 500);
}