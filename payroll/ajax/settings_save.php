<?php
// /payroll/ajax/settings_save.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$monthlyAnchor = (int)($_POST['monthly_anchor_day'] ?? 25);
$fortnightAnchor = (int)($_POST['fortnight_anchor_day'] ?? 15);
$weeklyAnchor = (int)($_POST['weekly_anchor_day'] ?? 5);
$defaultFreq = $_POST['default_frequency'] ?? 'monthly';
$bankFormat = $_POST['bank_file_format'] ?? 'standard_bank_csv';
$rounding = $_POST['rounding_cents'] ?? 'nearest';
$autoPost = isset($_POST['auto_post_to_finance']) ? 1 : 0;
$variancePct = floatval($_POST['require_approval_for_variance_pct'] ?? 10);
$wageGL = trim($_POST['default_wage_gl_code'] ?? '6000');
$payeGL = trim($_POST['default_paye_gl_code'] ?? '2100');
$uifGL = trim($_POST['default_uif_gl_code'] ?? '2101');
$sdlGL = trim($_POST['default_sdl_gl_code'] ?? '2102');

try {
    $DB->beginTransaction();

    $stmt = $DB->prepare("
        UPDATE payroll_settings SET
            monthly_anchor_day = ?,
            fortnight_anchor_day = ?,
            weekly_anchor_day = ?,
            default_frequency = ?,
            bank_file_format = ?,
            rounding_cents = ?,
            auto_post_to_finance = ?,
            require_approval_for_variance_pct = ?,
            default_wage_gl_code = ?,
            default_paye_gl_code = ?,
            default_uif_gl_code = ?,
            default_sdl_gl_code = ?,
            updated_at = NOW()
        WHERE company_id = ?
    ");
    $stmt->execute([
        $monthlyAnchor, $fortnightAnchor, $weeklyAnchor,
        $defaultFreq, $bankFormat, $rounding,
        $autoPost, $variancePct,
        $wageGL, $payeGL, $uifGL, $sdlGL,
        $companyId
    ]);

    // Audit
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'payroll_settings_updated', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId, $userId,
        json_encode(['frequency' => $defaultFreq, 'bank_format' => $bankFormat]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Settings save error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Save failed']);
}