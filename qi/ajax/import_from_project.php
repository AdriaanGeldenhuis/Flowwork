<?php
// /qi/ajax/import_from_project.php
// Import line items from a project board into a quote or invoice

require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../db.php';

header('Content-Type: application/json');

// Only accept POST with JSON body
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$boardId = isset($input['board_id']) ? (int)$input['board_id'] : 0;
$companyId = $_SESSION['company_id'] ?? null;

if (!$boardId || !$companyId) {
    echo json_encode(['ok' => false, 'error' => 'Missing board_id']);
    exit;
}

try {
    // Verify board belongs to current company via project association
    // project_boards table has board_id, project_id, company_id
    $stmt = $pdo->prepare("SELECT pb.project_id, p.name AS project_name FROM project_boards pb JOIN projects p ON pb.project_id = p.project_id WHERE pb.board_id = ? AND pb.company_id = ? AND p.company_id = ? LIMIT 1");
    $stmt->execute([$boardId, $companyId, $companyId]);
    $boardInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$boardInfo) {
        echo json_encode(['ok' => false, 'error' => 'Board not found']);
        exit;
    }

    // Fetch numeric columns for this board
    $stmtCols = $pdo->prepare("SELECT column_id, name FROM board_columns WHERE board_id = ? AND type = 'number' ORDER BY column_id");
    $stmtCols->execute([$boardId]);
    $cols = $stmtCols->fetchAll(PDO::FETCH_ASSOC);
    $quantityCol = null;
    $priceCol = null;
    $totalCol = null;
    $taxCol = null;
    $firstCol = null;
    foreach ($cols as $col) {
        $colId = (int)$col['column_id'];
        $colName = strtolower($col['name'] ?? '');
        if ($firstCol === null) {
            $firstCol = $colId;
        }
        if ($quantityCol === null && (strpos($colName, 'qty') !== false || strpos($colName, 'quantity') !== false)) {
            $quantityCol = $colId;
            continue;
        }
        if ($priceCol === null && (strpos($colName, 'price') !== false || strpos($colName, 'rate') !== false || strpos($colName, 'unit') !== false || strpos($colName, 'cost') !== false)) {
            $priceCol = $colId;
            continue;
        }
        if ($totalCol === null && (strpos($colName, 'total') !== false || strpos($colName, 'amount') !== false || strpos($colName, 'amt') !== false)) {
            $totalCol = $colId;
            continue;
        }
        if ($taxCol === null && (strpos($colName, 'tax') !== false || strpos($colName, 'vat') !== false)) {
            $taxCol = $colId;
            continue;
        }
    }

    // Determine default tax rate from qi_settings if available
    $defaultTaxRate = 15.0;
    try {
        $stmtTax = $pdo->prepare("SELECT default_tax_rate FROM qi_settings WHERE company_id = ? LIMIT 1");
        $stmtTax->execute([$companyId]);
        $row = $stmtTax->fetch(PDO::FETCH_ASSOC);
        if ($row && is_numeric($row['default_tax_rate'])) {
            $defaultTaxRate = floatval($row['default_tax_rate']);
        }
    } catch (Exception $ex) {
        // ignore if table not found or error
    }

    // Fetch board items and their values
    $stmtItems = $pdo->prepare("SELECT id, title FROM board_items WHERE board_id = ? AND (status IS NULL OR status NOT IN ('archived','deleted')) ORDER BY id");
    $stmtItems->execute([$boardId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    $stmtVals = $pdo->prepare("SELECT column_id, value FROM board_item_values WHERE item_id = ?");
    foreach ($items as $item) {
        $itemId = (int)$item['id'];
        $title = trim($item['title'] ?? '');
        $stmtVals->execute([$itemId]);
        $values = $stmtVals->fetchAll(PDO::FETCH_ASSOC);
        $valMap = [];
        foreach ($values as $v) {
            $colId = (int)$v['column_id'];
            // Attempt to parse numeric value; some values may contain non-numeric characters
            $num = null;
            if (isset($v['value'])) {
                // Remove any non-digit except dot and comma
                $raw = $v['value'];
                // replace comma decimal with dot
                $raw = str_replace(',', '.', $raw);
                // remove spaces and currency symbols
                $raw = preg_replace('/[^0-9.\-]+/', '', $raw);
                if (is_numeric($raw)) {
                    $num = floatval($raw);
                }
            }
            if ($num !== null) {
                $valMap[$colId] = $num;
            }
        }
        // Determine quantity
        $qty = 1.0;
        if ($quantityCol && isset($valMap[$quantityCol])) {
            $qtyVal = $valMap[$quantityCol];
            if ($qtyVal > 0) {
                $qty = $qtyVal;
            }
        }
        // Determine unit price
        $unitPrice = 0.0;
        if ($priceCol && isset($valMap[$priceCol])) {
            $unitPrice = $valMap[$priceCol];
        } elseif ($totalCol && isset($valMap[$totalCol])) {
            // derive unit price from total / qty
            $totalVal = $valMap[$totalCol];
            $unitPrice = $qty > 0 ? ($totalVal / $qty) : $totalVal;
        } elseif ($firstCol && isset($valMap[$firstCol])) {
            $unitPrice = $valMap[$firstCol];
        }
        // Determine tax rate
        $taxRate = $defaultTaxRate;
        if ($taxCol && isset($valMap[$taxCol])) {
            $taxCandidate = $valMap[$taxCol];
            // If value is less than 1, treat as decimal fraction (e.g., 0.15 => 15)
            if ($taxCandidate > 0 && $taxCandidate < 1) {
                $taxCandidate = $taxCandidate * 100;
            }
            $taxRate = round($taxCandidate, 2);
        }
        $results[] = [
            'description' => $title,
            'quantity'    => (float)$qty,
            'unit_price'  => (float)$unitPrice,
            'tax_rate'    => (float)$taxRate
        ];
    }

    echo json_encode(['ok' => true, 'items' => $results]);

} catch (Exception $e) {
    error_log('Import from project error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to import items']);
}