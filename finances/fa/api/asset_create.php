<?php
// /finances/fa/api/asset_create.php
// Creates a new fixed asset record based on JSON input.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';

// HTTP method guard
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

// CSRF validation
require_once __DIR__ . '/../../lib/Csrf.php';
Csrf::validate();

// Include permissions helper and restrict to admins and bookkeepers
require_once __DIR__ . '/../../permissions.php';
requireRoles(['admin', 'bookkeeper']);

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

header('Content-Type: application/json');

// Read input
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid request body']);
    exit;
}

try {
    $assetName  = trim($data['asset_name'] ?? '');
    $category   = trim($data['category'] ?? '') ?: null;
    $purchaseDate = $data['purchase_date'] ?? null;
    $purchaseCost = $data['purchase_cost'] ?? null;
    $salvageVal  = $data['salvage_value'] ?? null;
    $lifeMonths  = $data['useful_life_months'] ?? null;
    $method      = $data['depreciation_method'] ?? '';
    $assetAccId  = $data['asset_account_id'] ?? null;
    $expAccId    = $data['depreciation_expense_account_id'] ?? null;
    $accumAccId  = $data['accumulated_depreciation_account_id'] ?? null;
    // Validate
    if (!$assetName || !$purchaseDate || !is_numeric($purchaseCost) || !is_numeric($salvageVal) || !is_numeric($lifeMonths) || !$method || !$assetAccId || !$expAccId || !$accumAccId) {
        throw new Exception('Missing or invalid fields');
    }
    // Convert monetary values to cents
    $costCents   = (int)round(floatval($purchaseCost) * 100);
    $salvageCents = (int)round(floatval($salvageVal) * 100);
    $lifeMonths  = (int)$lifeMonths;
    $method      = in_array($method, ['straight_line','declining_balance']) ? $method : 'straight_line';
    // Insert asset
    $stmt = $DB->prepare(
        "INSERT INTO gl_fixed_assets (
            company_id, asset_name, category, purchase_date,
            purchase_cost_cents, salvage_value_cents, useful_life_months,
            depreciation_method, asset_account_id, depreciation_expense_account_id,
            accumulated_depreciation_account_id, accumulated_depreciation_cents, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'active')"
    );
    $stmt->execute([
        $companyId,
        $assetName,
        $category,
        $purchaseDate,
        $costCents,
        $salvageCents,
        $lifeMonths,
        $method,
        (int)$assetAccId,
        (int)$expAccId,
        (int)$accumAccId
    ]);
    $assetId = (int)$DB->lastInsertId();
    // Audit log
    $stmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp) VALUES (?, ?, 'fa_asset_created', ?, ?, NOW())"
    );
    $stmt->execute([$companyId, $userId, json_encode(['asset_id' => $assetId, 'asset_name' => $assetName, 'cost' => $purchaseCost]), $_SERVER['REMOTE_ADDR'] ?? null]);
    echo json_encode(['success' => true, 'data' => ['asset_id' => $assetId], 'message' => 'Asset created successfully']);
} catch (Exception $e) {
    error_log('FA asset create error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to create asset']);
}
?>