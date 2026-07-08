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

// CSRF validation (session already bootstrapped via init.php above; Csrf is
// loaded globally by init.php — the class_exists guard covers the /app layout)
if (!class_exists('Csrf')) {
    require_once $__fin_root . '/finances/lib/Csrf.php';
}
Csrf::validate();

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
    'vat_basis',
    'require_sod',
    'ar_account_id',
    'ap_account_id',
    'bank_account_id',
    'vat_output_account_id',
    'vat_input_account_id',
    'vat_control_account_id',
    'sales_account_id',
    'cogs_account_id',
    'inventory_account_id',
    'expense_account_id',
    'gain_on_disposal_account_id',
    'loss_on_disposal_account_id',
    // Payroll (EMP201), forex, bad debt and year-end mappings — previously
    // reachable by the posting engine (AccountsMap) but not editable here.
    'paye_account_id',
    'uif_account_id',
    'sdl_account_id',
    'wage_expense_account_id',
    'uif_expense_account_id',
    'sdl_expense_account_id',
    'fx_gain_account_id',
    'fx_loss_account_id',
    'bad_debt_account_id',
    'retained_earnings_account_id'
];

// Validate enumerated settings
if (isset($input['vat_basis']) && !in_array($input['vat_basis'], ['invoice', 'payments'], true)) {
    echo json_encode(['ok' => false, 'error' => 'VAT basis must be invoice or payments']);
    exit;
}

// Guard a VAT-basis change. Switching invoice<->payments is a SARS election
// change (payments basis is a restricted s15(2) election) that requires manual
// s16(3) transitional adjustments, and it must not happen while a VAT period is
// prepared but not yet filed — that period was prepared under the old basis.
// Filed periods are frozen to the basis stamped at prepare time, so they do not
// block; only in-flight prepared/adjusted periods do.
if (isset($input['vat_basis'])) {
    $curStmt = $DB->prepare(
        "SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = 'finance_vat_basis' LIMIT 1"
    );
    $curStmt->execute([$companyId]);
    $currentBasis = $curStmt->fetchColumn() ?: 'invoice';
    if ($input['vat_basis'] !== $currentBasis) {
        $vpStmt = $DB->prepare(
            "SELECT COUNT(*) FROM gl_vat_periods WHERE company_id = ? AND status IN ('prepared','adjusted')"
        );
        $vpStmt->execute([$companyId]);
        if ((int)$vpStmt->fetchColumn() > 0) {
            echo json_encode(['ok' => false, 'error' => 'Cannot change the VAT basis while a VAT period is prepared but not yet filed. File or discard the in-flight period first.']);
            exit;
        }
    }
}
if (isset($input['require_sod']) && !in_array((string)$input['require_sod'], ['0', '1'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid segregation-of-duties value']);
    exit;
}

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

// --- Server-side validation ---

// fiscal_year_start: the settings form posts a month NAME (January..December),
// consumed by year_end.php via strtotime(). Reject anything else.
if (array_key_exists('fiscal_year_start', $updates)) {
    $validMonths = ['January','February','March','April','May','June',
                    'July','August','September','October','November','December'];
    if (!is_string($updates['fiscal_year_start']) || !in_array($updates['fiscal_year_start'], $validMonths, true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid fiscal year start month']);
        exit;
    }
}

// *_account_id settings: must be positive integers referencing a gl_accounts
// row owned by this company, AND of the correct account_type for the role.
// Without the type check an admin could map e.g. VAT Output to a revenue
// account — which both hides the SARS liability on the balance sheet AND
// contaminates the VAT201, because VatCalculator sums journal lines by the
// mapped account code. Null/empty clears the mapping and is allowed. VAT
// input/control also accept 'asset' since some charts present VAT receivable
// as a current asset rather than a contra-liability.
$expectedTypes = [
    'ar_account_id'                => ['asset'],
    'ap_account_id'                => ['liability'],
    'bank_account_id'              => ['asset'],
    'vat_output_account_id'        => ['liability'],
    'vat_input_account_id'         => ['liability', 'asset'],
    'vat_control_account_id'       => ['liability', 'asset'],
    'sales_account_id'             => ['revenue'],
    'cogs_account_id'              => ['expense'],
    'inventory_account_id'         => ['asset'],
    'expense_account_id'           => ['expense'],
    'gain_on_disposal_account_id'  => ['revenue'],
    'loss_on_disposal_account_id'  => ['expense'],
    'paye_account_id'              => ['liability'],
    'uif_account_id'               => ['liability'],
    'sdl_account_id'               => ['liability'],
    'wage_expense_account_id'      => ['expense'],
    'uif_expense_account_id'       => ['expense'],
    'sdl_expense_account_id'       => ['expense'],
    'fx_gain_account_id'           => ['revenue'],
    'fx_loss_account_id'           => ['expense'],
    'bad_debt_account_id'          => ['expense'],
    'retained_earnings_account_id' => ['equity'],
];
$accCheckStmt = $DB->prepare(
    "SELECT account_type FROM gl_accounts WHERE company_id = ? AND account_id = ? LIMIT 1"
);
foreach ($updates as $key => $value) {
    if (substr($key, -11) !== '_account_id' || $value === null) {
        continue;
    }
    $isIntLike = is_int($value) || (is_string($value) && ctype_digit($value));
    $accId = $isIntLike ? (int)$value : 0;
    if ($accId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid account id for ' . $key]);
        exit;
    }
    $accCheckStmt->execute([$companyId, $accId]);
    $accType = $accCheckStmt->fetchColumn();
    if ($accType === false) {
        echo json_encode(['ok' => false, 'error' => 'Account for ' . $key . ' does not exist for this company']);
        exit;
    }
    if (isset($expectedTypes[$key]) && !in_array($accType, $expectedTypes[$key], true)) {
        echo json_encode(['ok' => false, 'error' => 'The account for ' . $key . ' must be of type ' . implode(' or ', $expectedTypes[$key])]);
        exit;
    }
    $updates[$key] = $accId;
}

try {
    $DB->beginTransaction();
    // Upsert each setting key atomically. company_settings has a unique key on
    // (company_id, setting_key), so ON DUPLICATE KEY UPDATE avoids the
    // check-then-write race the previous version could hit under concurrent saves.
    $upsert = $DB->prepare(
        "INSERT INTO company_settings (company_id, setting_key, setting_value, updated_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
    );
    foreach ($updates as $key => $value) {
        // Store '' rather than NULL for a cleared mapping: setting_value is
        // TEXT NOT NULL, so under strict sql_mode binding NULL aborts the whole
        // save (errno 1048). AccountsMap treats '' and NULL identically (both
        // fall back to the default), so a cleared mapping still behaves the same.
        $upsert->execute([$companyId, 'finance_' . $key, $value ?? '']);
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
    $msg = ($e instanceof PDOException) ? 'Failed to save settings' : $e->getMessage();
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
