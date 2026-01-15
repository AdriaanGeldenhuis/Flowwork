<?php
// /qi/ai/suggest_lines.php
// Suggest line items based on provided context or project board

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

header('Content-Type: application/json');

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$context = isset($input['context']) ? trim($input['context']) : '';
$projectId = isset($input['project_id']) ? (int)$input['project_id'] : 0;

// Determine default tax rate from settings
$companyId = $_SESSION['company_id'] ?? 0;
$defaultTaxRate = 15.0;
if ($companyId) {
    $stmt = $DB->prepare("SELECT default_tax_rate FROM qi_settings WHERE company_id = ? LIMIT 1");
    $stmt->execute([$companyId]);
    $row = $stmt->fetch();
    if ($row && is_numeric($row['default_tax_rate'])) {
        $defaultTaxRate = (float)$row['default_tax_rate'];
    }
}

// Basic heuristics for suggestions
$suggestions = [];

// If a project_id is provided, fetch board items to suggest
if ($projectId > 0) {
    try {
        // Fetch items from board_items for the given project boards (join board_items -> projects?)
        $stmt = $DB->prepare("SELECT bi.title FROM board_items bi JOIN project_boards pb ON bi.board_id = pb.board_id WHERE pb.project_id = ? AND bi.status IN ('todo','in_progress','done') ORDER BY bi.title LIMIT 10");
        $stmt->execute([$projectId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as $item) {
            $desc = trim($item['title']);
            if ($desc) {
                $suggestions[] = [
                    'description' => $desc,
                    'quantity'    => 1,
                    'unit_price'  => 0,
                    'tax_rate'    => $defaultTaxRate,
                    'gl_account'  => null
                ];
            }
        }
    } catch (Exception $e) {
        error_log('AI suggest lines: ' . $e->getMessage());
    }
}

// If context text provided, simple keyword matching
if ($context) {
    $text = strtolower($context);
    if (strpos($text, 'design') !== false) {
        $suggestions[] = [
            'description' => 'Design services',
            'quantity'    => 1,
            'unit_price'  => 0,
            'tax_rate'    => $defaultTaxRate,
            'gl_account'  => null
        ];
    }
    if (strpos($text, 'develop') !== false || strpos($text, 'development') !== false) {
        $suggestions[] = [
            'description' => 'Development services',
            'quantity'    => 1,
            'unit_price'  => 0,
            'tax_rate'    => $defaultTaxRate,
            'gl_account'  => null
        ];
    }
    if (strpos($text, 'consult') !== false) {
        $suggestions[] = [
            'description' => 'Consulting services',
            'quantity'    => 1,
            'unit_price'  => 0,
            'tax_rate'    => $defaultTaxRate,
            'gl_account'  => null
        ];
    }
    // Generic fallback
    if (empty($suggestions)) {
        $suggestions[] = [
            'description' => 'Service fee',
            'quantity'    => 1,
            'unit_price'  => 0,
            'tax_rate'    => $defaultTaxRate,
            'gl_account'  => null
        ];
        $suggestions[] = [
            'description' => 'Materials',
            'quantity'    => 1,
            'unit_price'  => 0,
            'tax_rate'    => $defaultTaxRate,
            'gl_account'  => null
        ];
    }
}

// If still empty (no context and project didn't yield), provide generic items
if (empty($suggestions)) {
    $suggestions[] = [
        'description' => 'Consultation',
        'quantity'    => 1,
        'unit_price'  => 0,
        'tax_rate'    => $defaultTaxRate,
        'gl_account'  => null
    ];
    $suggestions[] = [
        'description' => 'Labour',
        'quantity'    => 1,
        'unit_price'  => 0,
        'tax_rate'    => $defaultTaxRate,
        'gl_account'  => null
    ];
}

echo json_encode(['ok' => true, 'data' => ['items' => $suggestions]]);
exit;