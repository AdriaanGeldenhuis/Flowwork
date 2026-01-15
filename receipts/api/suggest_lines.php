<?php
// Returns suggested line items based on OCR data and supplier defaults.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

if (!isset($_SESSION['company_id']) || !isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];

// Parse JSON input
$input = json_decode(file_get_contents('php://input'), true);
$fileId = isset($input['file_id']) ? (int)$input['file_id'] : 0;
$supplierId = isset($input['supplier_id']) ? (int)$input['supplier_id'] : 0;

if (!$fileId) {
    echo json_encode(['ok' => false, 'error' => 'file_id required']);
    exit;
}

// Fetch line items from receipt_ocr
$stmt = $DB->prepare("SELECT line_items_json FROM receipt_ocr WHERE file_id = ? AND file_id IN (SELECT file_id FROM receipt_file WHERE company_id = ?)");
$stmt->execute([$fileId, $companyId]);
$row = $stmt->fetch();

$lines = [];
if ($row && $row['line_items_json']) {
    $decoded = json_decode($row['line_items_json'], true);
    if (is_array($decoded)) {
        foreach ($decoded as $l) {
            $lines[] = [
                'description' => $l['description'] ?? '',
                'qty' => (float)($l['qty'] ?? 1),
                'unit' => $l['unit'] ?? 'ea',
                'unit_price' => (float)($l['unit_price'] ?? 0),
                'gl_account_id' => null,
                'flag_spike' => false
            ];
        }
    }
}

// If no lines found, return an empty default line
if (empty($lines)) {
    $lines[] = [
        'description' => '',
        'qty' => 1,
        'unit' => 'ea',
        'unit_price' => 0,
        'gl_account_id' => null,
        'flag_spike' => false
    ];
}

// ==== Section 6: Line normalization, GL guess, price benchmarking ====

// Determine a default GL account guess for this supplier.  This helper will
// check our learned AI mapping (stored in company_settings.receipts_ai_map)
// before falling back to the last used GL account for this supplier or the
// first expense account.  It also returns the token map for later use.
function getAiMapping($DB, $companyId) {
    $stmt = $DB->prepare(
        "SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = 'receipts_ai_map' LIMIT 1"
    );
    $stmt->execute([$companyId]);
    $row = $stmt->fetch();
    if ($row && $row['setting_value']) {
        $map = json_decode($row['setting_value'], true);
        if (is_array($map)) {
            // Ensure structure has expected keys
            $map += ['supplier_default_gl' => [], 'token_map' => []];
            return $map;
        }
    }
    return ['supplier_default_gl' => [], 'token_map' => []];
}

function getDefaultGlAccount($DB, $companyId, $supplierId, $aiMap) {
    // First check AI map for this supplier
    if ($supplierId && isset($aiMap['supplier_default_gl'][$supplierId])) {
        $candidate = (int)$aiMap['supplier_default_gl'][$supplierId];
        if ($candidate > 0) {
            return $candidate;
        }
    }
    // Try to get last used GL account for this supplier
    if ($supplierId) {
        $stmt = $DB->prepare(
            "SELECT bl.gl_account_id
             FROM ap_bill_lines bl
             JOIN ap_bills b ON b.id = bl.bill_id
             WHERE b.company_id = ? AND b.supplier_id = ? AND bl.gl_account_id IS NOT NULL
             ORDER BY bl.id DESC
             LIMIT 1"
        );
        $stmt->execute([$companyId, $supplierId]);
        $row = $stmt->fetch();
        if ($row && $row['gl_account_id']) {
            return (int)$row['gl_account_id'];
        }
    }
    // Fallback to first expense account
    $stmt = $DB->prepare(
        "SELECT account_id
         FROM gl_accounts
         WHERE company_id = ? AND account_type = 'expense'
         ORDER BY account_code
         LIMIT 1"
    );
    $stmt->execute([$companyId]);
    $row = $stmt->fetch();
    return $row ? (int)$row['account_id'] : null;
}

// Fetch AI mapping and default GL account guess
$aiMap = getAiMapping($DB, $companyId);
$defaultGlId = getDefaultGlAccount($DB, $companyId, $supplierId, $aiMap);
$tokenMap = isset($aiMap['token_map']) && is_array($aiMap['token_map']) ? $aiMap['token_map'] : [];

// Helper: fetch a hint value for a given key/type
function fetchHintValue($DB, $companyId, $type, $key) {
    $stmt = $DB->prepare(
        "SELECT hint_value
         FROM ai_hints
         WHERE company_id = ? AND hint_type = ? AND hint_key = ?
         ORDER BY hits DESC, updated_at DESC
         LIMIT 1"
    );
    $stmt->execute([$companyId, $type, strtolower($key)]);
    $row = $stmt->fetch();
    return $row ? $row['hint_value'] : null;
}

// Price benchmarking: For each line, compute average past price for this supplier and description
foreach ($lines as &$line) {
    // Normalise missing fields
    if (!isset($line['qty']) || $line['qty'] <= 0) {
        $line['qty'] = 1;
    }
    if (empty($line['unit'])) {
        $line['unit'] = 'ea';
    }
    if (!isset($line['unit_price'])) {
        $line['unit_price'] = 0;
    }
    // Guess GL account using token map first, then default
    $glGuess = null;
    if (!empty($tokenMap) && !empty($line['description'])) {
        // Lowercase description and split into tokens on non-alphanumerics
        $descLower = strtolower($line['description']);
        $tokens = preg_split('/[^a-z0-9]+/', $descLower, -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens) {
            foreach ($tokens as $tok) {
                if (isset($tokenMap[$tok]) && (int)$tokenMap[$tok] > 0) {
                    $glGuess = (int)$tokenMap[$tok];
                    break;
                }
            }
        }
    }
    // Use GL guess from token if found, else supplier default
    $line['gl_account_id'] = $glGuess ?: $defaultGlId;
    // Price spike detection only if supplier provided
    $line['flag_spike'] = false;
    if ($supplierId && !empty($line['description']) && $line['unit_price'] > 0) {
        // Compute average price for this supplier and description over last 60 days
        $stmt = $DB->prepare(
            "SELECT AVG(bl.unit_price) as avg_price, COUNT(*) as cnt
             FROM ap_bill_lines bl
             JOIN ap_bills b ON b.id = bl.bill_id
             WHERE b.company_id = ? AND b.supplier_id = ?
             AND bl.item_description = ?
             AND b.issue_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)"
        );
        $stmt->execute([$companyId, $supplierId, $line['description']]);
        $stats = $stmt->fetch();
        if ($stats && $stats['cnt'] > 0) {
            $avg = (float)$stats['avg_price'];
            if ($avg > 0 && $line['unit_price'] > $avg * 1.2) {
                $line['flag_spike'] = true;
            }
        }
    }
}
unset($line);

// Build suggestions for each line
foreach ($lines as &$line) {
    // Prepare suggestions arrays
    $glSuggestions = [];
    $projectSuggestions = [];
    // 1) Hint-based GL suggestion
    if (!empty($line['description'])) {
        $hintVal = fetchHintValue($DB, $companyId, 'gl', $line['description']);
        if ($hintVal) {
            // Ensure numeric ID
            $hintId = (int)$hintVal;
            if ($hintId > 0) {
                $glSuggestions[$hintId] = 0.95;
            }
        }
    }
    // 2) Token map suggestion (tokenMap) for GL
    $glTok = null;
    if (!empty($tokenMap) && !empty($line['description'])) {
        $descLower = strtolower($line['description']);
        $tokens = preg_split('/[^a-z0-9]+/', $descLower, -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens) {
            foreach ($tokens as $tok) {
                if (isset($tokenMap[$tok]) && (int)$tokenMap[$tok] > 0) {
                    $glTok = (int)$tokenMap[$tok];
                    break;
                }
            }
        }
    }
    if ($glTok) {
        // Do not override hint; if same id, keep highest confidence
        $glSuggestions[$glTok] = max($glSuggestions[$glTok] ?? 0, 0.80);
    }
    // 3) Supplier default GL as a low confidence suggestion
    if ($defaultGlId) {
        $glSuggestions[$defaultGlId] = max($glSuggestions[$defaultGlId] ?? 0, 0.40);
    }
    // Convert suggestions map to array format {id, confidence}
    $glSuggestionsList = [];
    foreach ($glSuggestions as $gid => $conf) {
        $glSuggestionsList[] = ['id' => (int)$gid, 'confidence' => round($conf, 2)];
    }
    // No project suggestions for now (reserved for future hint types)
    $projectSuggestionsList = [];
    // Attach to line
    $line['gl_suggestions'] = $glSuggestionsList;
    $line['project_suggestions'] = $projectSuggestionsList;
}
unset($line);

// Optionally compute learned metrics
$learned = ['gl_guess_hit_rate' => 1.0];

echo json_encode(['ok' => true, 'lines' => $lines, 'learned' => $learned]);