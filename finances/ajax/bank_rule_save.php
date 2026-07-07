<?php
// /finances/ajax/bank_rule_save.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/Csrf.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

Csrf::validate();

// Role check
$role = strtolower($_SESSION['role'] ?? 'member');
if (!in_array($role, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);

$ruleName = trim($input['rule_name'] ?? '');
$matchField = $input['match_field'] ?? 'description';
$matchOperator = $input['match_operator'] ?? 'contains';
$matchValue = trim($input['match_value'] ?? '');
$glAccountId = !empty($input['gl_account_id']) ? (int)$input['gl_account_id'] : null;
$taxCodeId = !empty($input['tax_code_id']) ? (int)$input['tax_code_id'] : null;
$descriptionTemplate = trim($input['description_template'] ?? '');

if (!$ruleName || !$matchValue || !$glAccountId) {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

// Only allow match field/operator combinations the apply engine actually
// implements (bank_apply_rules.php). The DB enum is wider (ends_with, regex,
// amount) but those are unimplemented, so a rule using them would save yet
// silently never match — reject them at creation instead.
$allowedFields    = ['description', 'reference'];
$allowedOperators = ['contains', 'starts_with', 'equals'];
if (!in_array($matchField, $allowedFields, true)) {
    echo json_encode(['ok' => false, 'error' => 'Unsupported match field']);
    exit;
}
if (!in_array($matchOperator, $allowedOperators, true)) {
    echo json_encode(['ok' => false, 'error' => 'Unsupported match operator']);
    exit;
}

// Validate GL account belongs to this company
$stmt = $DB->prepare("SELECT account_id FROM gl_accounts WHERE account_id = ? AND company_id = ?");
$stmt->execute([$glAccountId, $companyId]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Invalid GL account']);
    exit;
}

// Validate tax code belongs to this company
if ($taxCodeId !== null) {
    $stmt = $DB->prepare("SELECT tax_code_id FROM gl_tax_codes WHERE tax_code_id = ? AND company_id = ? AND is_active = 1");
    $stmt->execute([$taxCodeId, $companyId]);
    if (!$stmt->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Invalid tax code']);
        exit;
    }
}

try {
    $DB->beginTransaction();

    $stmt = $DB->prepare("
        INSERT INTO gl_bank_rules (
            company_id, rule_name, match_field, match_operator, match_value,
            gl_account_id, tax_code_id, description_template, priority, is_active, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 100, 1, NOW())
    ");
    $stmt->execute([
        $companyId,
        $ruleName,
        $matchField,
        $matchOperator,
        $matchValue,
        $glAccountId,
        $taxCodeId,
        $descriptionTemplate
    ]);

    $ruleId = $DB->lastInsertId();

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'bank_rule_created', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['rule_id' => $ruleId, 'name' => $ruleName]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode([
        'ok' => true,
        'data' => ['rule_id' => $ruleId]
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Bank rule save error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to save rule'
    ]);
}