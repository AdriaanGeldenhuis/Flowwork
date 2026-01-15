<?php
// /shopping/ajax/ai_item_parse.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$text = trim($input['text'] ?? '');

if (empty($text)) {
    echo json_encode(['ok' => false, 'error' => 'No text provided']);
    exit;
}

// Split by newlines OR semicolons
$text = str_replace(';', "\n", $text); // Convert semicolons to newlines
$lines = explode("\n", $text);
$items = [];

foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line)) continue;

    $item = parseItemLine($line);
    if ($item) {
        $items[] = $item;
    }
}

echo json_encode(['ok' => true, 'items' => $items]);

function parseItemLine($line) {
    // Remove leading/trailing whitespace and common separators
    $line = trim($line);
    $line = preg_replace('/^[,;:\-\s]+/', '', $line); // Remove leading punctuation
    
    if (empty($line)) return null;
    
    // Pattern 1: "2x PVC elbow 20mm" or "2 x PVC elbow"
    if (preg_match('/^(\d+\.?\d*)\s*x\s*(.+)$/i', $line, $m)) {
        $qty = floatval($m[1]);
        $name = trim($m[2]);
        return [
            'qty' => $qty,
            'name' => normalizeItemName($name),
            'unit' => extractUnit($name)
        ];
    }
    
    // Pattern 2: "6m conduit 16mm" (qty with unit embedded at start)
    if (preg_match('/^(\d+\.?\d*)\s*(m|mm|cm|km|kg|g|L|ml|ft|in|ea|pcs?)\s+(.+)$/i', $line, $m)) {
        return [
            'qty' => floatval($m[1]),
            'unit' => strtolower($m[2]),
            'name' => normalizeItemName(trim($m[3]))
        ];
    }
    
    // Pattern 3: "milk 2L" or "bread 500g" (qty at end)
    if (preg_match('/^(.+?)\s+(\d+\.?\d*)\s*(m|mm|cm|kg|g|L|ml|ea|pcs?)?$/i', $line, $m)) {
        return [
            'qty' => floatval($m[2]),
            'unit' => !empty($m[3]) ? strtolower($m[3]) : 'ea',
            'name' => normalizeItemName(trim($m[1]))
        ];
    }
    
    // Pattern 4: Just "2 item_name" (number at start, no 'x')
    if (preg_match('/^(\d+\.?\d*)\s+(.+)$/i', $line, $m)) {
        return [
            'qty' => floatval($m[1]),
            'unit' => 'ea',
            'name' => normalizeItemName(trim($m[2]))
        ];
    }
    
    // Pattern 5: Just a name, default to 1 ea
    return [
        'qty' => 1,
        'unit' => 'ea',
        'name' => normalizeItemName($line)
    ];
}

function normalizeItemName($name) {
    // Remove trailing units if present
    $name = preg_replace('/\s+\d+\s*(m|mm|cm|kg|g|L|ml|ea|pcs?)$/i', '', $name);
    
    // Trim and capitalize
    $name = trim($name);
    
    // Capitalize first letter of each word
    $name = ucwords(strtolower($name));
    
    // Preserve common acronyms
    $synonyms = [
        'pvc' => 'PVC',
        'dbr' => 'DBR',
        'ppe' => 'PPE',
        'diy' => 'DIY',
        'led' => 'LED'
    ];
    
    foreach ($synonyms as $k => $v) {
        $name = preg_replace('/\b' . $k . '\b/i', $v, $name);
    }
    
    return $name;
}

function extractUnit($text) {
    // Extract unit from text if present
    if (preg_match('/\b(m|mm|cm|km|kg|g|L|ml|ea|pcs?|ft|in)\b/i', $text, $m)) {
        return strtolower($m[1]);
    }
    return 'ea';
}