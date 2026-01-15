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

define('ASSET_VERSION', '2025-01-21-FIN-1');

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
    'ar_account_id'          => $raw['finance_ar_account_id']          ?? '',
    'ap_account_id'          => $raw['finance_ap_account_id']          ?? '',
    'vat_output_account_id'  => $raw['finance_vat_output_account_id']  ?? '',
    'vat_input_account_id'   => $raw['finance_vat_input_account_id']   ?? '',
    'sales_account_id'       => $raw['finance_sales_account_id']       ?? '',
    'cogs_account_id'        => $raw['finance_cogs_account_id']        ?? '',
    'inventory_account_id'   => $raw['finance_inventory_account_id']   ?? ''
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Settings – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
</head>
<body>
    <main class="fw-finance">
        <div class="fw-finance__container">
            
            <!-- Header -->
            <header class="fw-finance__header">
                <div class="fw-finance__brand">
                    <div class="fw-finance__logo-tile">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="fw-finance__brand-text">
                        <div class="fw-finance__company-name"><?= htmlspecialchars($companyName) ?></div>
                        <div class="fw-finance__app-name">Finance Settings</div>
                    </div>
                </div>

                <div class="fw-finance__greeting">
                    Hello, <span class="fw-finance__greeting-name"><?= htmlspecialchars($firstName) ?></span>
                </div>

                <div class="fw-finance__controls">
                    <a href="/finances/" class="fw-finance__back-btn" title="Back to Dashboard">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    
                    <a href="/" class="fw-finance__home-btn" title="Home">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    
                    <button class="fw-finance__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                        <svg class="fw-finance__theme-icon fw-finance__theme-icon--light" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/>
                            <line x1="12" y1="1" x2="12" y2="3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <line x1="12" y1="21" x2="12" y2="23" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <line x1="1" y1="12" x2="3" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <line x1="21" y1="12" x2="23" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <svg class="fw-finance__theme-icon fw-finance__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>
            </header>

            <!-- Main Content -->
            <div class="fw-finance__main">
                
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
                                $months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                                foreach ($months as $month) {
                                    $sel = ($settings['fiscal_year_start'] === $month) ? 'selected' : '';
                                    echo '<option value="' . htmlspecialchars($month) . '" ' . $sel . '>' . htmlspecialchars($month) . '</option>';
                                }
                                ?>
                            </select>
                            <span class="fw-finance__help-text">The month your company's fiscal year begins</span>
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

                        // Render all account mapping selects
                        renderSelect('ar_account_id', 'Accounts Receivable', 'Default account for customer invoices', $settings, $accounts);
                        renderSelect('ap_account_id', 'Accounts Payable', 'Default account for supplier bills', $settings, $accounts);
                        renderSelect('vat_output_account_id', 'VAT Output (Sales Tax)', 'Tax collected on sales', $settings, $accounts);
                        renderSelect('vat_input_account_id', 'VAT Input (Purchase Tax)', 'Tax paid on purchases', $settings, $accounts);
                        renderSelect('sales_account_id', 'Sales Revenue', 'Default sales income account', $settings, $accounts);
                        renderSelect('cogs_account_id', 'Cost of Goods Sold', 'Default COGS expense account', $settings, $accounts);
                        renderSelect('inventory_account_id', 'Inventory Asset', 'Default inventory tracking account', $settings, $accounts);
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
                        <li><strong>VAT Accounts:</strong> Track sales and purchase tax obligations</li>
                        <li><strong>Sales/COGS/Inventory:</strong> Used in transaction posting and inventory valuation</li>
                    </ul>
                    <p style="margin-top: 12px; color: var(--fw-text-muted); font-size: 13px;">
                        💡 Tip: You can override these defaults on individual transactions if needed.
                    </p>
                </div>

            </div>

            <!-- Footer -->
            <footer class="fw-finance__footer">
                <span>Finance v<?= ASSET_VERSION ?></span>
                <span>Settings last updated: <?= date('Y-m-d H:i') ?></span>
            </footer>

        </div>
    </main>

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
                    headers: { 'Content-Type': 'application/json' },
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