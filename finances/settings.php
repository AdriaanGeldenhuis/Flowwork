<?php
// /finances/settings.php
// Finance settings page - Admin only

// Dynamically load init, auth and permissions
$__fin_root = realpath(__DIR__ . '/..');
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

// Restrict to admin users
requireRoles(['admin']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

$companyId = $_SESSION['company_id'] ?? null;
$userId    = $_SESSION['user_id'] ?? null;
if (!$companyId || !$userId) {
    header('Location: /login.php');
    exit;
}

// Fetch user info
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

// Fetch company name
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Fetch existing finance settings
$stmt = $DB->prepare(
    "SELECT setting_key, setting_value
     FROM company_settings
     WHERE company_id = ? AND setting_key LIKE 'finance_%'"
);
$stmt->execute([$companyId]);
$raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Populate default settings
$settings = [
    'fiscal_year_start'      => $raw['finance_fiscal_year_start']      ?? '',
    'vat_basis'              => $raw['finance_vat_basis']              ?? 'invoice',
    'require_sod'            => $raw['finance_require_sod']            ?? '0',
    'ar_account_id'          => $raw['finance_ar_account_id']          ?? '',
    'ap_account_id'          => $raw['finance_ap_account_id']          ?? '',
    'vat_output_account_id'  => $raw['finance_vat_output_account_id']  ?? '',
    'vat_input_account_id'   => $raw['finance_vat_input_account_id']   ?? '',
    'sales_account_id'       => $raw['finance_sales_account_id']       ?? '',
    'cogs_account_id'        => $raw['finance_cogs_account_id']        ?? '',
    'inventory_account_id'           => $raw['finance_inventory_account_id']           ?? '',
    'bank_account_id'                => $raw['finance_bank_account_id']                ?? '',
    'vat_control_account_id'         => $raw['finance_vat_control_account_id']         ?? '',
    'expense_account_id'             => $raw['finance_expense_account_id']             ?? '',
    'gain_on_disposal_account_id'    => $raw['finance_gain_on_disposal_account_id']    ?? '',
    'loss_on_disposal_account_id'    => $raw['finance_loss_on_disposal_account_id']    ?? '',
    'paye_account_id'                => $raw['finance_paye_account_id']                ?? '',
    'uif_account_id'                 => $raw['finance_uif_account_id']                 ?? '',
    'sdl_account_id'                 => $raw['finance_sdl_account_id']                 ?? '',
    'wage_expense_account_id'        => $raw['finance_wage_expense_account_id']        ?? '',
    'uif_expense_account_id'         => $raw['finance_uif_expense_account_id']         ?? '',
    'sdl_expense_account_id'         => $raw['finance_sdl_expense_account_id']         ?? '',
    'fx_gain_account_id'             => $raw['finance_fx_gain_account_id']             ?? '',
    'fx_loss_account_id'             => $raw['finance_fx_loss_account_id']             ?? '',
    'bad_debt_account_id'            => $raw['finance_bad_debt_account_id']            ?? '',
    'retained_earnings_account_id'   => $raw['finance_retained_earnings_account_id']   ?? ''
];

// Load active GL accounts for dropdowns
$stmt = $DB->prepare(
    "SELECT account_id, account_code, account_name
     FROM gl_accounts
     WHERE company_id = ? AND is_active = 1
     ORDER BY account_code"
);
$stmt->execute([$companyId]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Include any account a mapping currently points to but which is now inactive.
// The dropdowns are built from active accounts only, so without this an inactive
// mapped account has no matching option — the select falls back to the blank
// "Select account", and simply re-saving the form would post an empty value and
// silently wipe the mapping (then posting falls back to a possibly-wrong default
// code). Appending the mapped-but-inactive accounts keeps them selected.
$activeIds = [];
foreach ($accounts as $a) { $activeIds[(int)$a['account_id']] = true; }
$mappedIds = [];
foreach ($settings as $sk => $sv) {
    if (substr($sk, -11) === '_account_id' && ctype_digit((string)$sv)) {
        $id = (int)$sv;
        if (!isset($activeIds[$id])) { $mappedIds[$id] = true; }
    }
}
if ($mappedIds) {
    $ids = array_keys($mappedIds);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $DB->prepare(
        "SELECT account_id, account_code, account_name FROM gl_accounts
         WHERE company_id = ? AND account_id IN ($ph) ORDER BY account_code"
    );
    $st->execute(array_merge([$companyId], $ids));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['account_name'] .= ' (inactive)';
        $accounts[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <title>Finance Settings – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-finance">
    <div class="fw-finance__container">
            
            <!-- Header -->
            <?php $finTitle = 'Finance Settings'; $finBack = '/finances/'; $finCompanyName = $companyName; $finFirstName = $firstName; include __DIR__ . '/partials/header.php'; ?>

            <!-- Main Content -->
            <main class="fw-finance__main">
                
                <div class="fw-finance__section-title" style="margin-bottom: 24px;">
                    Configure Default GL Account Mappings
                </div>

                <div class="fw-finance__form-card">
                    <form id="settingsForm">
                        
                        <!-- Fiscal Year Start -->
                        <div class="fw-finance__form-group">
                            <label class="fw-finance__label" for="fiscal_year_start">
                                Fiscal Year Start Month <span class="fw-finance__required">*</span>
                            </label>
                            <select id="fiscal_year_start" name="fiscal_year_start" class="fw-finance__input" required>
                                <option value="">Select month</option>
                                <?php
                                // Normalise the stored value to a month name so the correct month
                                // preselects whether it was saved as a name (this page) or a number
                                // (admin/finance.php). Unset stays blank to force an explicit choice.
                                require_once __DIR__ . '/lib/CoaSchema.php';
                                $fyStartName = ($settings['fiscal_year_start'] !== '')
                                    ? date('F', mktime(0, 0, 0, CoaSchema::parseFiscalMonth($settings['fiscal_year_start']), 1))
                                    : '';
                                $months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                                foreach ($months as $month) {
                                    $sel = ($fyStartName === $month) ? 'selected' : '';
                                    echo '<option value="' . htmlspecialchars($month) . '" ' . $sel . '>' . htmlspecialchars($month) . '</option>';
                                }
                                ?>
                            </select>
                            <span class="fw-finance__help-text">The month your company's fiscal year begins</span>
                        </div>

                        <!-- VAT accounting basis -->
                        <div class="fw-finance__form-group">
                            <label class="fw-finance__label" for="vat_basis">
                                VAT Accounting Basis <span class="fw-finance__required">*</span>
                            </label>
                            <select id="vat_basis" name="vat_basis" class="fw-finance__input">
                                <option value="invoice" <?= $settings['vat_basis'] !== 'payments' ? 'selected' : '' ?>>Invoice basis (accrual) — VAT when invoices are issued/received</option>
                                <option value="payments" <?= $settings['vat_basis'] === 'payments' ? 'selected' : '' ?>>Payments basis (cash) — VAT when money is received/paid</option>
                            </select>
                            <span class="fw-finance__help-text">
                                Must match your SARS VAT registration. Payments basis is only available
                                to qualifying vendors (s15(2) VAT Act). Changing this mid-stream affects
                                how open VAT periods are calculated — confirm with your bookkeeper first.
                            </span>
                        </div>

                        <!-- Segregation of duties -->
                        <div class="fw-finance__form-group">
                            <label class="fw-finance__label" for="require_sod">
                                Journal Segregation of Duties
                            </label>
                            <select id="require_sod" name="require_sod" class="fw-finance__input">
                                <option value="0" <?= $settings['require_sod'] !== '1' ? 'selected' : '' ?>>Off — one user may create, approve and post</option>
                                <option value="1" <?= $settings['require_sod'] === '1' ? 'selected' : '' ?>>On — approver must differ from the preparer</option>
                            </select>
                            <span class="fw-finance__help-text">Recommended when more than one finance user exists</span>
                        </div>

                        <div style="height: 32px;"></div>

                        <!-- Account Mappings -->
                        <div class="fw-finance__form-card-title" style="font-size: 16px; margin-bottom: 20px;">
                            Default Account Mappings
                        </div>

                        <?php
                        // Helper to generate select field
                        function renderSelect($name, $label, $helpText, $settings, $accounts) {
                            echo '<div class="fw-finance__form-group">';
                            echo '<label class="fw-finance__label" for="' . $name . '">' . $label . '</label>';
                            echo '<select id="' . $name . '" name="' . $name . '" class="fw-finance__input">';
                            echo '<option value="">Select account</option>';
                            foreach ($accounts as $acc) {
                                $selected = ($settings[$name] == $acc['account_id']) ? 'selected' : '';
                                $codeName = htmlspecialchars($acc['account_code'] . ' - ' . $acc['account_name']);
                                echo '<option value="' . $acc['account_id'] . '" ' . $selected . '>' . $codeName . '</option>';
                            }
                            echo '</select>';
                            if ($helpText) {
                                echo '<span class="fw-finance__help-text">' . htmlspecialchars($helpText) . '</span>';
                            }
                            echo '</div>';
                        }

                        // --- Receivables & Payments ---
                        renderSelect('ar_account_id', 'Accounts Receivable', 'Default account for customer invoices', $settings, $accounts);
                        renderSelect('ap_account_id', 'Accounts Payable', 'Default account for supplier bills', $settings, $accounts);
                        renderSelect('bank_account_id', 'Bank Account', 'Default bank account for payment posting', $settings, $accounts);

                        echo '<div style="height: 24px;"></div>';
                        echo '<div class="fw-finance__form-card-title" style="font-size: 14px; margin-bottom: 16px; color: var(--fw-text-secondary);">Tax Accounts</div>';

                        // --- Tax Accounts ---
                        renderSelect('vat_output_account_id', 'VAT Output (Sales Tax)', 'Tax collected on sales', $settings, $accounts);
                        renderSelect('vat_input_account_id', 'VAT Input (Purchase Tax)', 'Tax paid on purchases', $settings, $accounts);
                        renderSelect('vat_control_account_id', 'VAT Control', 'VAT settlement/control account for adjustments', $settings, $accounts);

                        echo '<div style="height: 24px;"></div>';
                        echo '<div class="fw-finance__form-card-title" style="font-size: 14px; margin-bottom: 16px; color: var(--fw-text-secondary);">Revenue, Cost &amp; Inventory</div>';

                        // --- Revenue, Cost & Inventory ---
                        renderSelect('sales_account_id', 'Sales Revenue', 'Default sales income account', $settings, $accounts);
                        renderSelect('cogs_account_id', 'Cost of Goods Sold', 'Default COGS expense account', $settings, $accounts);
                        renderSelect('inventory_account_id', 'Inventory Asset', 'Default inventory tracking account', $settings, $accounts);
                        renderSelect('expense_account_id', 'Default Expense', 'Fallback expense account for AP bills without a GL account', $settings, $accounts);

                        echo '<div style="height: 24px;"></div>';
                        echo '<div class="fw-finance__form-card-title" style="font-size: 14px; margin-bottom: 16px; color: var(--fw-text-secondary);">Fixed Asset Disposal</div>';

                        // --- Fixed Asset Disposal ---
                        renderSelect('gain_on_disposal_account_id', 'Gain on Disposal', 'Income account for fixed asset disposal gains', $settings, $accounts);
                        renderSelect('loss_on_disposal_account_id', 'Loss on Disposal', 'Expense account for fixed asset disposal losses', $settings, $accounts);

                        echo '<div style="height: 24px;"></div>';
                        echo '<div class="fw-finance__form-card-title" style="font-size: 14px; margin-bottom: 16px; color: var(--fw-text-secondary);">Payroll (EMP201)</div>';

                        // --- Payroll liabilities & expenses ---
                        renderSelect('paye_account_id', 'PAYE Payable', 'Employees\' tax withheld, owed to SARS', $settings, $accounts);
                        renderSelect('uif_account_id', 'UIF Payable', 'UIF contributions owed to SARS', $settings, $accounts);
                        renderSelect('sdl_account_id', 'SDL Payable', 'Skills development levy owed to SARS', $settings, $accounts);
                        renderSelect('wage_expense_account_id', 'Salaries & Wages Expense', 'Gross remuneration expense account', $settings, $accounts);
                        renderSelect('uif_expense_account_id', 'UIF Employer Expense', 'Employer UIF contribution expense', $settings, $accounts);
                        renderSelect('sdl_expense_account_id', 'SDL Employer Expense', 'Employer skills development levy expense', $settings, $accounts);

                        echo '<div style="height: 24px;"></div>';
                        echo '<div class="fw-finance__form-card-title" style="font-size: 14px; margin-bottom: 16px; color: var(--fw-text-secondary);">Foreign Exchange &amp; Write-offs</div>';

                        // --- Forex + bad debt ---
                        renderSelect('fx_gain_account_id', 'Realised Forex Gain', 'Income account for realised foreign-exchange gains', $settings, $accounts);
                        renderSelect('fx_loss_account_id', 'Realised Forex Loss', 'Expense account for realised foreign-exchange losses', $settings, $accounts);
                        renderSelect('bad_debt_account_id', 'Bad Debts Written Off', 'Expense account for customer write-offs (s22 VAT relief)', $settings, $accounts);

                        echo '<div style="height: 24px;"></div>';
                        echo '<div class="fw-finance__form-card-title" style="font-size: 14px; margin-bottom: 16px; color: var(--fw-text-secondary);">Equity</div>';

                        // --- Year-end ---
                        renderSelect('retained_earnings_account_id', 'Retained Earnings', 'Accumulated-profit account the year-end close posts net income to', $settings, $accounts);
                        ?>

                        <div id="formMessage"></div>

                        <div class="fw-finance__form-actions" style="margin-top: 32px;">
                            <a href="/finances/" class="fw-finance__btn fw-finance__btn--secondary">Cancel</a>
                            <button type="submit" id="saveBtn" class="fw-finance__btn fw-finance__btn--primary">
                                Save Settings
                            </button>
                        </div>
                    </form>
                </div>

                <div class="fw-finance__info-card" style="margin-top: 32px;">
                    <div class="fw-finance__info-card-title">About Finance Settings</div>
                    <p style="margin-bottom: 12px; color: var(--fw-text-secondary); line-height: 1.6;">
                        These settings define the default general ledger accounts used throughout the finance module.
                    </p>
                    <ul style="color: var(--fw-text-secondary); line-height: 1.8; padding-left: 20px;">
                        <li><strong>Fiscal Year Start:</strong> Determines reporting periods and year-end calculations</li>
                        <li><strong>AR/AP Accounts:</strong> Used for customer invoices and supplier bills</li>
                        <li><strong>Bank Account:</strong> Default bank GL account used when posting customer and supplier payments</li>
                        <li><strong>VAT Accounts:</strong> Track sales and purchase tax obligations</li>
                        <li><strong>VAT Control:</strong> Settlement account used during VAT period adjustments</li>
                        <li><strong>Sales/COGS/Inventory:</strong> Used in transaction posting and inventory valuation</li>
                        <li><strong>Default Expense:</strong> Fallback expense account for AP bill lines without a specified GL account</li>
                        <li><strong>Gain/Loss on Disposal:</strong> Income and expense accounts for fixed asset disposal journals</li>
                    </ul>
                    <p style="margin-top: 12px; color: var(--fw-text-muted); font-size: 13px;">
                        💡 Tip: You can override these defaults on individual transactions if needed.
                    </p>
                </div>

            </main>

            <!-- Footer -->
            <footer class="fw-finance__footer">
                <span>Finance Settings v<?= ASSET_VERSION ?></span>
                <span id="themeIndicator">Theme: Light</span>
            </footer>

    </div>

    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
        document.getElementById('settingsForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const saveBtn = document.getElementById('saveBtn');
            const formMessage = document.getElementById('formMessage');
            const form = e.target;
            
            // Clear previous message
            formMessage.style.display = 'none';
            formMessage.textContent = '';
            
            // Collect form data
            const payload = {};
            const formData = new FormData(form);
            formData.forEach((value, key) => {
                payload[key] = value;
            });
            
            // Disable submit button
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving...';
            
            try {
                const response = await fetch('/finances/ajax/settings_save.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify(payload)
                });
                
                const data = await response.json();
                
                if (data.ok) {
                    formMessage.className = 'fw-finance__alert fw-finance__alert--success';
                    formMessage.textContent = '✓ Settings saved successfully!';
                    formMessage.style.display = 'block';
                    
                    // Redirect after success
                    setTimeout(() => {
                        window.location.href = '/finances/';
                    }, 1500);
                } else {
                    formMessage.className = 'fw-finance__alert fw-finance__alert--error';
                    formMessage.textContent = '✗ ' + (data.error || 'Failed to save settings');
                    formMessage.style.display = 'block';
                }
            } catch (err) {
                formMessage.className = 'fw-finance__alert fw-finance__alert--error';
                formMessage.textContent = '✗ Network error. Please try again.';
                formMessage.style.display = 'block';
            } finally {
                saveBtn.disabled = false;
                saveBtn.textContent = 'Save Settings';
            }
        });
    </script>
</body>
</html>