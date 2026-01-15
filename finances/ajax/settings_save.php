<?php
// /finances/ajax/settings_save.php
//
// Endpoint to save finance settings (account mappings and fiscal year start).
// Only users with admin privileges may update these settings. Settings are
// stored in the company_settings table with keys prefixed by 'finance_'.

// Dynamically include init, auth, and permissions to support both /app and root structures.
$__fin_root = realpath(__DIR__ . '/../../');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
    $permPath = $__fin_root . '/app/finances/permissions.php';
    if (file_exists($permPath)) {
        require_once $permPath;
    }
} else {
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
    $permPath = $__fin_root . '/finances/permissions.php';
    if (file_exists($permPath)) {
        require_once $permPath;
    }
}

header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

// Only admin can save finance settings
requireRoles(['admin']);

$companyId = $_SESSION['company_id'] ?? null;
$userId    = $_SESSION['user_id'] ?? null;
if (!$companyId || !$userId) {
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

// Parse JSON payload
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

// Define the list of finance settings we allow to be updated
$allowedKeys = [
    'fiscal_year_start',
    'ar_account_id',
    'ap_account_id',
    'vat_output_account_id',
    'vat_input_account_id',
    'sales_account_id',
    'cogs_account_id',
    'inventory_account_id'
];

// Prepare data for update
$updates = [];
foreach ($allowedKeys as $key) {
    if (array_key_exists($key, $input)) {
        $value = $input[$key];
        // Normalize empty strings or zero to null for account ids
        if ($value === '' || $value === null) {
            $value = null;
        }
        $updates[$key] = $value;
    }
}
if (empty($updates)) {
    echo json_encode(['ok' => false, 'error' => 'No settings provided']);
    exit;
}

try {
    $DB->beginTransaction();
    // Upsert each setting key
    foreach ($updates as $key => $value) {
        $settingKey = 'finance_' . $key;
        // Check if setting exists
        $stmt = $DB->prepare(
            "SELECT COUNT(*) FROM company_settings WHERE company_id = ? AND setting_key = ?"
        );
        $stmt->execute([$companyId, $settingKey]);
        $exists = $stmt->fetchColumn() > 0;
        if ($exists) {
            // Update existing
            $stmt = $DB->prepare(
                "UPDATE company_settings SET setting_value = ?, updated_at = NOW()
                 WHERE company_id = ? AND setting_key = ?"
            );
            $stmt->execute([$value, $companyId, $settingKey]);
        } else {
            // Insert new
            $stmt = $DB->prepare(
                "INSERT INTO company_settings (company_id, setting_key, setting_value, created_at, updated_at)
                 VALUES (?, ?, ?, NOW(), NOW())"
            );
            $stmt->execute([$companyId, $settingKey, $value]);
        }
    }
    // Audit log entry
    $stmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
         VALUES (?, ?, 'finance_settings_saved', ?, ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['updated' => array_keys($updates)]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();
    echo json_encode(['ok' => true]);
    exit;

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('Finance settings save error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}
