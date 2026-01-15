<?php
// /payroll/settings.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-PAYROLL-1');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Get settings
$stmt = $DB->prepare("SELECT * FROM payroll_settings WHERE company_id = ?");
$stmt->execute([$companyId]);
$settings = $stmt->fetch();

if (!$settings) {
    // Create default
    $stmt = $DB->prepare("INSERT INTO payroll_settings (company_id) VALUES (?)");
    $stmt->execute([$companyId]);
    $settings = [
        'monthly_anchor_day' => 25,
        'fortnight_anchor_day' => 15,
        'weekly_anchor_day' => 5,
        'default_frequency' => 'monthly',
        'bank_file_format' => 'standard_bank_csv',
        'rounding_cents' => 'nearest',
        'auto_post_to_finance' => 0,
        'require_approval_for_variance_pct' => 10.00,
        'default_wage_gl_code' => '6000',
        'default_paye_gl_code' => '2100',
        'default_uif_gl_code' => '2101',
        'default_sdl_gl_code' => '2102'
    ];
}

// Get tax tables
$stmt = $DB->query("
    SELECT * FROM tax_tables_za 
    WHERE company_id IS NULL OR company_id = $companyId
    ORDER BY effective_from DESC
");
$taxTables = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Settings – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/payroll/assets/payroll.css?v=<?= ASSET_VERSION ?>">
</head>
<body>
    <main class="fw-payroll">
        <div class="fw-payroll__container">
            
            <!-- Header -->
            <header class="fw-payroll__header">
                <div class="fw-payroll__brand">
                    <div class="fw-payroll__logo-tile">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="3" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                            <rect x="14" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                            <rect x="3" y="14" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                            <rect x="14" y="14" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </div>
                    <div class="fw-payroll__brand-text">
                        <div class="fw-payroll__company-name"><?= htmlspecialchars($companyName) ?></div>
                        <div class="fw-payroll__app-name">Payroll</div>
                    </div>
                </div>

                <div class="fw-payroll__greeting">
                    Hello, <span class="fw-payroll__greeting-name"><?= htmlspecialchars($firstName) ?></span>
                </div>

                <div class="fw-payroll__controls">
                    <a href="/" class="fw-payroll__home-btn" title="Home">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    
                    <button class="fw-payroll__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                        <svg class="fw-payroll__theme-icon fw-payroll__theme-icon--light" viewBox="0 0 24 24" fill="none">
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
                        <svg class="fw-payroll__theme-icon fw-payroll__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>

                    <div class="fw-payroll__menu-wrapper">
                        <button class="fw-payroll__kebab-toggle" id="kebabToggle" aria-label="Menu">
                            <svg viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                                <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                                <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                            </svg>
                        </button>
                        <nav class="fw-payroll__kebab-menu" id="kebabMenu" aria-hidden="true">
                            <a href="/payroll/" class="fw-payroll__kebab-item">Dashboard</a>
                            <a href="/payroll/employees.php" class="fw-payroll__kebab-item">Employees</a>
                            <a href="/payroll/payitems.php" class="fw-payroll__kebab-item">Pay Items</a>
                            <a href="/payroll/runs.php" class="fw-payroll__kebab-item">Pay Runs</a>
                            <a href="/payroll/reports.php" class="fw-payroll__kebab-item">Reports</a>
                        </nav>
                    </div>
                </div>
            </header>

            <!-- Main Content -->
            <div class="fw-payroll__main">
                
                <div class="fw-payroll__page-header">
                    <h1 class="fw-payroll__page-title">Payroll Settings</h1>
                    <p class="fw-payroll__page-subtitle">Configure pay periods, tax tables, and integrations</p>
                </div>

                <div class="fw-payroll__settings-container">
                    
                    <!-- General Settings -->
                    <form id="settingsForm" class="fw-payroll__settings-card">
                        <h3 class="fw-payroll__settings-card-title">General Settings</h3>
                        
                        <div class="fw-payroll__form-section">
                            <h4 class="fw-payroll__form-section-title">Pay Periods</h4>
                            
                            <div class="fw-payroll__form-row">
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">Monthly Anchor Day</label>
                                    <input type="number" name="monthly_anchor_day" class="fw-payroll__input" 
                                           value="<?= $settings['monthly_anchor_day'] ?>" min="1" max="31">
                                    <small class="fw-payroll__help-text">Day of month for monthly pay date (e.g., 25th)</small>
                                </div>
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">Default Frequency</label>
                                    <select name="default_frequency" class="fw-payroll__input">
                                        <option value="monthly" <?= $settings['default_frequency'] === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                        <option value="fortnight" <?= $settings['default_frequency'] === 'fortnight' ? 'selected' : '' ?>>Fortnight</option>
                                        <option value="weekly" <?= $settings['default_frequency'] === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                    </select>
                                </div>
                            </div>

                            <div class="fw-payroll__form-row">
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">Fortnight Anchor Day</label>
                                    <input type="number" name="fortnight_anchor_day" class="fw-payroll__input" 
                                           value="<?= $settings['fortnight_anchor_day'] ?>" min="1" max="31">
                                </div>
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">Weekly Anchor Day</label>
                                    <select name="weekly_anchor_day" class="fw-payroll__input">
                                        <option value="1" <?= $settings['weekly_anchor_day'] == 1 ? 'selected' : '' ?>>Monday</option>
                                        <option value="2" <?= $settings['weekly_anchor_day'] == 2 ? 'selected' : '' ?>>Tuesday</option>
                                        <option value="3" <?= $settings['weekly_anchor_day'] == 3 ? 'selected' : '' ?>>Wednesday</option>
                                        <option value="4" <?= $settings['weekly_anchor_day'] == 4 ? 'selected' : '' ?>>Thursday</option>
                                        <option value="5" <?= $settings['weekly_anchor_day'] == 5 ? 'selected' : '' ?>>Friday</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="fw-payroll__form-section">
                            <h4 class="fw-payroll__form-section-title">Calculation Settings</h4>
                            
                            <div class="fw-payroll__form-row">
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">Rounding</label>
                                    <select name="rounding_cents" class="fw-payroll__input">
                                        <option value="nearest" <?= $settings['rounding_cents'] === 'nearest' ? 'selected' : '' ?>>Nearest Cent</option>
                                        <option value="down" <?= $settings['rounding_cents'] === 'down' ? 'selected' : '' ?>>Round Down</option>
                                        <option value="up" <?= $settings['rounding_cents'] === 'up' ? 'selected' : '' ?>>Round Up</option>
                                    </select>
                                </div>
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">Variance Approval Threshold (%)</label>
                                    <input type="number" name="require_approval_for_variance_pct" class="fw-payroll__input" 
                                           value="<?= $settings['require_approval_for_variance_pct'] ?>" step="0.01" min="0">
                                    <small class="fw-payroll__help-text">Require manager approval if net pay changes by more than this %</small>
                                </div>
                            </div>

                            <div class="fw-payroll__form-group">
                                <label class="fw-payroll__checkbox-wrapper">
                                    <input type="checkbox" name="auto_post_to_finance" class="fw-payroll__checkbox" 
                                           <?= $settings['auto_post_to_finance'] ? 'checked' : '' ?>>
                                    Auto-post to Finance when run is locked
                                </label>
                            </div>
                        </div>

                        <div class="fw-payroll__form-section">
                            <h4 class="fw-payroll__form-section-title">GL Account Mapping</h4>
                            
                            <div class="fw-payroll__form-row">
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">Wage Expense GL Code</label>
                                    <input type="text" name="default_wage_gl_code" class="fw-payroll__input" 
                                           value="<?= $settings['default_wage_gl_code'] ?>">
                                </div>
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">PAYE Liability GL Code</label>
                                    <input type="text" name="default_paye_gl_code" class="fw-payroll__input" 
                                           value="<?= $settings['default_paye_gl_code'] ?>">
                                </div>
                            </div>

                            <div class="fw-payroll__form-row">
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">UIF Liability GL Code</label>
                                    <input type="text" name="default_uif_gl_code" class="fw-payroll__input" 
                                           value="<?= $settings['default_uif_gl_code'] ?>">
                                </div>
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">SDL Liability GL Code</label>
                                    <input type="text" name="default_sdl_gl_code" class="fw-payroll__input" 
                                           value="<?= $settings['default_sdl_gl_code'] ?>">
                                </div>
                            </div>
                        </div>

                        <div class="fw-payroll__form-section">
                            <h4 class="fw-payroll__form-section-title">Bank File Export</h4>
                            
                            <div class="fw-payroll__form-group">
                                <label class="fw-payroll__label">Bank File Format</label>
                                <select name="bank_file_format" class="fw-payroll__input">
                                    <option value="standard_bank_csv" <?= $settings['bank_file_format'] === 'standard_bank_csv' ? 'selected' : '' ?>>Standard Bank CSV</option>
                                    <option value="absa_npf" <?= $settings['bank_file_format'] === 'absa_npf' ? 'selected' : '' ?>>ABSA NPF</option>
                                    <option value="nedbank_naedo" <?= $settings['bank_file_format'] === 'nedbank_naedo' ? 'selected' : '' ?>>Nedbank NAEDO</option>
                                    <option value="generic_csv" <?= $settings['bank_file_format'] === 'generic_csv' ? 'selected' : '' ?>>Generic CSV</option>
                                </select>
                            </div>
                        </div>

                        <div id="settingsMessage"></div>

                        <div class="fw-payroll__settings-actions">
                            <button type="button" class="fw-payroll__btn fw-payroll__btn--primary" onclick="PayrollSettings.saveSettings()">
                                Save Settings
                            </button>
                        </div>
                    </form>

                    <!-- Tax Tables -->
                    <div class="fw-payroll__settings-card">
                        <h3 class="fw-payroll__settings-card-title">Tax Tables (ZA SARS)</h3>
                        
                        <div class="fw-payroll__tax-tables-list">
                            <?php foreach ($taxTables as $table): ?>
                            <div class="fw-payroll__tax-table-card">
                                <div class="fw-payroll__tax-table-header">
                                    <div>
                                        <div class="fw-payroll__tax-table-period">
                                            <?= date('d M Y', strtotime($table['effective_from'])) ?>
                                            <?php if ($table['effective_to']): ?>
                                                - <?= date('d M Y', strtotime($table['effective_to'])) ?>
                                            <?php else: ?>
                                                - Current
                                            <?php endif; ?>
                                        </div>
                                        <div class="fw-payroll__tax-table-scope">
                                            <?= $table['company_id'] ? 'Company Specific' : 'System Default' ?>
                                        </div>
                                    </div>
                                    <?php
                                    $rebatePrimary = $table['rebate_primary_cents'] / 100;
                                    $uifCap = $table['uif_cap_annual_cents'] / 100;
                                    ?>
                                    <div class="fw-payroll__tax-table-stats">
                                        <div class="fw-payroll__tax-table-stat">
                                            <span class="fw-payroll__tax-table-stat-label">Primary Rebate</span>
                                            <span class="fw-payroll__tax-table-stat-value">R <?= number_format($rebatePrimary, 2) ?></span>
                                        </div>
                                        <div class="fw-payroll__tax-table-stat">
                                            <span class="fw-payroll__tax-table-stat-label">UIF Cap (Annual)</span>
                                            <span class="fw-payroll__tax-table-stat-value">R <?= number_format($uifCap, 2) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <details class="fw-payroll__tax-table-details">
                                    <summary>View Tax Brackets</summary>
                                    <div class="fw-payroll__tax-brackets">
                                        <?php
                                        $brackets = json_decode($table['bracket_json'], true);
                                        foreach ($brackets as $bracket):
                                            $threshold = $bracket['threshold_cents'] / 100;
                                            $rate = $bracket['rate_bp'] / 100;
                                            $cumulative = $bracket['cumulative_cents'] / 100;
                                        ?>
                                        <div class="fw-payroll__tax-bracket">
                                            <span>R <?= number_format($threshold, 0) ?>+</span>
                                            <span><?= $rate ?>%</span>
                                            <span>Cumulative: R <?= number_format($cumulative, 2) ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <p class="fw-payroll__help-text" style="margin-top: 16px;">
                            Tax tables are managed by system admin. Contact support to update for new tax year.
                        </p>
                    </div>

                </div>

            </div>

            <footer class="fw-payroll__footer">
                <span>Payroll v<?= ASSET_VERSION ?></span>
                <span id="themeIndicator">Theme: Light</span>
            </footer>

        </div>
    </main>

    <script src="/payroll/assets/payroll.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/payroll/assets/settings.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>