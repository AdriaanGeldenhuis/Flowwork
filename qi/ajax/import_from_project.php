<?php
// /qi/ajax/import_from_project.php
// Import line items from a project board into a quote or invoice

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../projects/lib/autoload.php';

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
    $stmt = $DB->prepare("SELECT pb.project_id, p.name AS project_name FROM project_boards pb JOIN projects p ON pb.project_id = p.project_id WHERE pb.board_id = ? AND pb.company_id = ? AND p.company_id = ? LIMIT 1");
    $stmt->execute([$boardId, $companyId, $companyId]);
    $boardInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    // ...and that the user may open it (project or board member, or admin)
    if (!$boardInfo || !ProjectLink::canSeeBoard($DB, (int)$companyId, (int)$_SESSION['user_id'], (string)($_SESSION['role'] ?? 'viewer'), $boardId)) {
        echo json_encode(['ok' => false, 'error' => 'Board not found']);
        exit;
    }

    // Fetch numeric columns for this board
    $stmtCols = $DB->prepare("SELECT column_id, name FROM board_columns WHERE board_id = ? AND type = 'number' ORDER BY column_id");
    $stmtCols->execute([$boardId]);
    $cols = $stmtCols->fetchAll(PDO::FETCH_ASSOC);
    $quantityCol = null;
    $priceCol = null;
    $totalCol = null;
    $taxCol = null;
    $firstCol = null;
    // Recognise columns by name. VAT is checked first ("VAT rate" is not a price),
    // then price ("Unit price" is not a quantity), quantity, total, and the
    // looser price words last.
    $has = static function (string $name, array $words): bool {
        foreach ($words as $w) {
            if (strpos($name, $w) !== false) return true;
        }
        return false;
    };
    foreach ($cols as $col) {
        $colId = (int)$col['column_id'];
        $colName = strtolower($col['name'] ?? '');
        if ($taxCol === null && $has($colName, ['tax', 'vat'])) {
            $taxCol = $colId;
        } elseif ($priceCol === null && $has($colName, ['price'])) {
            $priceCol = $colId;
        } elseif ($quantityCol === null && $has($colName, ['qty', 'quantity', 'units', 'hours', 'hrs'])) {
            $quantityCol = $colId;
        } elseif ($totalCol === null && $has($colName, ['total', 'amount', 'amt'])) {
            $totalCol = $colId;
        } elseif ($priceCol === null && $has($colName, ['rate', 'cost', 'each', 'unit'])) {
            $priceCol = $colId;
        }
    }
    // no price or total column by name: the first other number column is the price
    foreach ($cols as $col) {
        if (!in_array((int)$col['column_id'], [$taxCol, $quantityCol, $priceCol, $totalCol], true)) {
            $firstCol = (int)$col['column_id'];
            break;
        }
    }

    // Determine default tax rate from qi_settings if available
    $defaultTaxRate = 15.0;
    try {
        $stmtTax = $DB->prepare("SELECT default_tax_rate FROM qi_settings WHERE company_id = ? LIMIT 1");
        $stmtTax->execute([$companyId]);
        $row = $stmtTax->fetch(PDO::FETCH_ASSOC);
        if ($row && is_numeric($row['default_tax_rate'])) {
            $defaultTaxRate = floatval($row['default_tax_rate']);
        }
    } catch (Exception $ex) {
        // ignore if table not found or error
    }

    // Fetch the board's groups so items import under their group heading,
    // in the same order they appear on the board.
    $groups = [];
    try {
        $stmtGroups = $DB->prepare("SELECT id, name FROM board_groups WHERE board_id = ? ORDER BY position ASC, id ASC");
        $stmtGroups->execute([$boardId]);
        $groups = $stmtGroups->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $ex) {
        // Boards without groups still import as a flat list
    }

    // Fetch board items in board order (group position, then item position)
    $stmtItems = $DB->prepare("SELECT id, group_id, title FROM board_items WHERE board_id = ? AND (archived = 0 OR archived IS NULL) ORDER BY position ASC, id ASC");
    $stmtItems->execute([$boardId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // Bucket items per group, preserving item order inside each group
    $itemsByGroup = [];
    foreach ($items as $item) {
        $gid = (int)($item['group_id'] ?? 0);
        $itemsByGroup[$gid][] = $item;
    }

    // Walk groups in board order; items whose group no longer exists tag on
    // at the end without a heading.
    $orderedGroups = [];
    foreach ($groups as $g) {
        $orderedGroups[] = ['id' => (int)$g['id'], 'name' => trim((string)$g['name'])];
    }
    $knownGroupIds = array_column($orderedGroups, 'id');
    $orphanIds = array_diff(array_keys($itemsByGroup), $knownGroupIds);
    foreach ($orphanIds as $oid) {
        $orderedGroups[] = ['id' => (int)$oid, 'name' => ''];
    }

    $results = [];
    $stmtVals = $DB->prepare("SELECT column_id, value FROM board_item_values WHERE item_id = ?");

    $buildItem = function (array $item) use ($stmtVals, $quantityCol, $priceCol, $totalCol, $taxCol, $firstCol, $defaultTaxRate) {
        $itemId = (int)$item['id'];
        $title = trim($item['title'] ?? '');
        $stmtVals->execute([$itemId]);
        $values = $stmtVals->fetchAll(PDO::FETCH_ASSOC);
        $valMap = [];
        foreach ($values as $v) {
            $num = isset($v['value']) ? qi_import_number((string)$v['value']) : null;
            if ($num !== null) {
                $valMap[(int)$v['column_id']] = $num;
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
            // derive the unit price from total / qty — unless that does not come
            // out in whole cents: then one line for the total, so the document
            // adds up to exactly what the board says
            $totalC = (int)round($valMap[$totalCol] * 100);
            $unitC = $qty > 0 ? (int)round($totalC / $qty) : $totalC;
            if ($qty > 0 && (int)round($unitC * $qty) === $totalC) {
                $unitPrice = $unitC / 100;
            } else {
                $unitPrice = $totalC / 100;
                $qty = 1.0;
            }
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
        return [
            'type'        => 'item',
            'description' => $title,
            'quantity'    => (float)$qty,
            'unit_price'  => (float)$unitPrice,
            'tax_rate'    => (float)$taxRate
        ];
    };

    foreach ($orderedGroups as $group) {
        $groupItems = $itemsByGroup[$group['id']] ?? [];
        if (empty($groupItems)) {
            continue; // empty groups don't add headings to the document
        }
        if ($group['name'] !== '') {
            $results[] = [
                'type'        => 'heading',
                'description' => $group['name'],
            ];
        }
        foreach ($groupItems as $item) {
            $results[] = $buildItem($item);
        }
    }

    echo json_encode(['ok' => true, 'items' => $results]);

} catch (Exception $e) {
    error_log('Import from project error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to import items']);
}

/**
 * A number typed on a board: "1 250,50", "R1,250.50", "1.250.000", "15%".
 * The last of "," / "." is the decimal mark when both appear; a single ","
 * before exactly three digits groups thousands ("1,250" = 1250), otherwise it
 * is the decimal comma ("12,5"); several of the same mark group thousands.
 */
function qi_import_number(string $raw): ?float
{
    $s = preg_replace('/[^0-9.,\-]+/', '', $raw) ?? '';
    if ($s === '' || !preg_match('/\d/', $s)) return null;
    $neg = str_starts_with($s, '-');
    $s = str_replace('-', '', $s);
    $lastDot = strrpos($s, '.');
    $lastComma = strrpos($s, ',');
    if ($lastDot !== false && $lastComma !== false) {
        $dec = $lastDot > $lastComma ? '.' : ',';
        $s = str_replace($dec === '.' ? ',' : '.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif ($lastComma !== false) {
        $s = (substr_count($s, ',') > 1 || preg_match('/^\d{1,3},\d{3}$/', $s)) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
    } elseif ($lastDot !== false && substr_count($s, '.') > 1) {
        $s = str_replace('.', '', $s);
    }
    if (!is_numeric($s)) return null;
    return $neg ? -(float)$s : (float)$s;
}
