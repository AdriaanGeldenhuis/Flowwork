<?php
// /qi/settings.php - COMPLETE WORKING VERSION WITH TEMPLATES
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-QI-FINAL');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

$stmt = $DB->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();

if (!$company) {
    die('Company not found');
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'update_company') {
        try {
            $stmt = $DB->prepare("
                UPDATE companies 
                SET name = ?, vat_number = ?, tax_number = ?, reg_number = ?,
                    phone = ?, email = ?, website = ?,
                    address_line1 = ?, address_line2 = ?, city = ?, region = ?, postal = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $_POST['name'], $_POST['vat_number'], $_POST['tax_number'], $_POST['reg_number'],
                $_POST['phone'], $_POST['email'], $_POST['website'],
                $_POST['address_line1'], $_POST['address_line2'], $_POST['city'], $_POST['region'], $_POST['postal'],
                $companyId
            ]);
            $message = 'Company details updated!';
            $messageType = 'success';
            
            $stmt = $DB->prepare("SELECT * FROM companies WHERE id = ?");
            $stmt->execute([$companyId]);
            $company = $stmt->fetch();
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    
    if ($_POST['action'] === 'update_banking') {
        try {
            $stmt = $DB->prepare("
                UPDATE companies 
                SET bank_name = ?, bank_account_number = ?, bank_branch_code = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $_POST['bank_name'], $_POST['bank_account_number'], $_POST['bank_branch_code'], $companyId
            ]);
            $message = 'Banking details updated!';
            $messageType = 'success';
            
            $stmt = $DB->prepare("SELECT * FROM companies WHERE id = ?");
            $stmt->execute([$companyId]);
            $company = $stmt->fetch();
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    
    if ($_POST['action'] === 'update_branding') {
        try {
            $stmt = $DB->prepare("
                UPDATE companies 
                SET primary_color = ?,
                    secondary_color = ?,
                    qi_font_family = ?,
                    quote_footer_text = ?,
                    invoice_footer_text = ?,
                    qi_show_company_address = ?,
                    qi_show_company_phone = ?,
                    qi_show_company_email = ?,
                    qi_show_company_website = ?,
                    qi_show_vat_number = ?,
                    qi_show_tax_number = ?,
                    qi_show_reg_number = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $_POST['primary_color'],
                $_POST['secondary_color'],
                $_POST['qi_font_family'],
                $_POST['quote_footer_text'],
                $_POST['invoice_footer_text'],
                isset($_POST['qi_show_company_address']) ? 1 : 0,
                isset($_POST['qi_show_company_phone']) ? 1 : 0,
                isset($_POST['qi_show_company_email']) ? 1 : 0,
                isset($_POST['qi_show_company_website']) ? 1 : 0,
                isset($_POST['qi_show_vat_number']) ? 1 : 0,
                isset($_POST['qi_show_tax_number']) ? 1 : 0,
                isset($_POST['qi_show_reg_number']) ? 1 : 0,
                $companyId
            ]);
            $message = 'Branding updated! Refresh quote/invoice to see changes.';
            $messageType = 'success';
            
            $stmt = $DB->prepare("SELECT * FROM companies WHERE id = ?");
            $stmt->execute([$companyId]);
            $company = $stmt->fetch();
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Q&I Settings – <?= htmlspecialchars($company['name']) ?></title>
    <link rel="stylesheet" href="/qi/assets/qi.css?v=<?= ASSET_VERSION ?>">
    <style>
        .fw-qi__color-input-wrapper { display: flex; gap: 12px; align-items: center; }
        .fw-qi__color-picker { width: 60px; height: 40px; border: 1px solid var(--fw-border); border-radius: 8px; cursor: pointer; }
        .fw-qi__input--color { flex: 1; font-family: monospace; text-transform: uppercase; }
        
        /* Font Grid */
        .fw-qi__font-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-top: 16px; }
        .fw-qi__font-card { cursor: pointer; }
        .fw-qi__font-card input[type="radio"] { display: none; }
        .fw-qi__font-preview { height: 100px; background: var(--fw-highlight); border: 2px solid var(--fw-border); border-radius: 12px; padding: 16px; display: flex; flex-direction: column; align-items: center; justify-content: center; transition: all 0.2s; }
        .fw-qi__font-card:hover .fw-qi__font-preview { box-shadow: var(--fw-shadow-sm); transform: translateY(-2px); }
        .fw-qi__font-card input:checked + .fw-qi__font-preview { border-color: var(--accent-qi); box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.2); }
        .fw-qi__font-sample { font-size: 36px; font-weight: 600; color: var(--accent-qi); }
        .fw-qi__font-name { font-size: 13px; font-weight: 600; margin-top: 8px; }
        
        /* Template Grid */
        .fw-qi__template-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-top: 16px; }
        .fw-qi__template-card { cursor: pointer; transition: all 0.2s ease; opacity: 1; }
        .fw-qi__template-card--disabled { opacity: 0.5; cursor: not-allowed; }
        .fw-qi__template-card input[type="radio"] { display: none; }
        .fw-qi__template-preview { height: 140px; background: var(--fw-highlight); border: 2px solid var(--fw-border); border-radius: 12px; padding: 16px; transition: all 0.2s ease; margin-bottom: 8px; display: flex; flex-direction: column; gap: 8px; }
        .fw-qi__template-card:hover .fw-qi__template-preview { box-shadow: var(--fw-shadow-sm); transform: translateY(-2px); }
        .fw-qi__template-card input:checked + .fw-qi__template-preview { border-color: var(--accent-qi); box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.2); background: rgba(251, 191, 36, 0.05); }
        .fw-qi__template-preview-header { font-weight: 700; font-size: 16px; color: var(--accent-qi); text-align: center; }
        .fw-qi__template-preview-body { flex: 1; display: flex; flex-direction: column; gap: 6px; justify-content: center; }
        .fw-qi__template-preview-line { height: 8px; background: var(--fw-border); border-radius: 4px; }
        .fw-qi__template-preview-line--short { width: 60%; }
        .fw-qi__template-preview-stripe { height: 6px; background: linear-gradient(90deg, var(--accent-qi), #f59e0b); border-radius: 4px; margin-bottom: 4px; }
        .fw-qi__template-name { text-align: center; font-size: 13px; font-weight: 600; color: var(--fw-text-primary); }
        .fw-qi__template-card--disabled .fw-qi__template-name { color: var(--fw-text-muted); }
        
        /* Checkbox Grid */
        .fw-qi__checkbox-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-top: 16px; }
        .fw-qi__checkbox-card { cursor: pointer; }
        .fw-qi__checkbox-card input[type="checkbox"] { display: none; }
        .fw-qi__checkbox-content { padding: 20px; background: var(--fw-highlight); border: 2px solid var(--fw-border); border-radius: 12px; display: flex; flex-direction: column; align-items: center; gap: 12px; min-height: 100px; justify-content: center; transition: all 0.2s; }
        .fw-qi__checkbox-card:hover .fw-qi__checkbox-content { box-shadow: var(--fw-shadow-sm); transform: translateY(-2px); }
        .fw-qi__checkbox-card input:checked + .fw-qi__checkbox-content { border-color: var(--accent-qi); background: rgba(251, 191, 36, 0.1); box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.15); }
        .fw-qi__checkbox-icon { font-size: 32px; }
        .fw-qi__checkbox-label { font-size: 13px; font-weight: 600; text-align: center; }
        
        @media (max-width: 1200px) {
            .fw-qi__template-grid, .fw-qi__font-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 768px) {
            .fw-qi__template-grid, .fw-qi__font-grid, .fw-qi__checkbox-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body class="fw-qi">
    <div class="fw-qi__container">
        
        <header class="fw-qi__header">
            <div class="fw-qi__brand">
                <div class="fw-qi__logo-tile">
                    <?php if ($company['logo_url']): ?>
                        <img src="<?= htmlspecialchars($company['logo_url']) ?>" alt="Logo" style="width:100%;height:100%;object-fit:contain;">
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/><path d="M12 1v6m0 6v6M23 12h-6m-6 0H1" stroke="currentColor" stroke-width="2"/></svg>
                    <?php endif; ?>
                </div>
                <div class="fw-qi__brand-text">
                    <div class="fw-qi__company-name"><?= htmlspecialchars($company['name']) ?></div>
                    <div class="fw-qi__app-name">Q&I Settings</div>
                </div>
            </div>

            <div class="fw-qi__greeting">Hello, <span class="fw-qi__greeting-name"><?= htmlspecialchars($firstName) ?></span></div>

            <div class="fw-qi__controls">
                <a href="/qi/" class="fw-qi__home-btn" title="Back"><svg viewBox="0 0 24 24" fill="none"><path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></a>
                <button class="fw-qi__theme-toggle" id="themeToggle"><svg class="fw-qi__theme-icon fw-qi__theme-icon--light" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/><line x1="12" y1="1" x2="12" y2="3" stroke="currentColor" stroke-width="2"/><line x1="12" y1="21" x2="12" y2="23" stroke="currentColor" stroke-width="2"/></svg><svg class="fw-qi__theme-icon fw-qi__theme-icon--dark" viewBox="0 0 24 24" fill="none"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2"/></svg></button>
            </div>
        </header>

        <main class="fw-qi__main">

            <div class="fw-qi__page-header">
                <h1 class="fw-qi__page-title">Q&I Settings</h1>
                <p class="fw-qi__page-subtitle">Customize your quotes and invoices</p>
            </div>

            <?php if ($message): ?>
                <div class="fw-qi__alert fw-qi__alert--<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <div class="fw-qi__settings-tabs">
                <button class="fw-qi__settings-tab fw-qi__settings-tab--active" data-tab="company">Company</button>
                <button class="fw-qi__settings-tab" data-tab="banking">Banking</button>
                <button class="fw-qi__settings-tab" data-tab="branding">Branding</button>
                <button class="fw-qi__settings-tab" data-tab="logo">Logo</button>
            </div>

            <!-- COMPANY TAB -->
            <div class="fw-qi__settings-panel fw-qi__settings-panel--active" data-panel="company">
                <form method="POST" class="fw-qi__settings-form">
                    <input type="hidden" name="action" value="update_company">
                    
                    <div class="fw-qi__form-section">
                        <h3 class="fw-qi__form-section-title">Company Information</h3>
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Company Name <span class="fw-qi__required">*</span></label>
                            <input type="text" name="name" class="fw-qi__input" value="<?= htmlspecialchars($company['name']) ?>" required>
                        </div>
                        <div class="fw-qi__form-row">
                            <div class="fw-qi__form-group">
                                <label class="fw-qi__label">VAT Number</label>
                                <input type="text" name="vat_number" class="fw-qi__input" value="<?= htmlspecialchars($company['vat_number'] ?? '') ?>">
                            </div>
                            <div class="fw-qi__form-group">
                                <label class="fw-qi__label">Tax Number</label>
                                <input type="text" name="tax_number" class="fw-qi__input" value="<?= htmlspecialchars($company['tax_number'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Registration Number</label>
                            <input type="text" name="reg_number" class="fw-qi__input" value="<?= htmlspecialchars($company['reg_number'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="fw-qi__form-section">
                        <h3 class="fw-qi__form-section-title">Contact</h3>
                        <div class="fw-qi__form-row">
                            <div class="fw-qi__form-group">
                                <label class="fw-qi__label">Phone</label>
                                <input type="text" name="phone" class="fw-qi__input" value="<?= htmlspecialchars($company['phone'] ?? '') ?>">
                            </div>
                            <div class="fw-qi__form-group">
                                <label class="fw-qi__label">Email</label>
                                <input type="email" name="email" class="fw-qi__input" value="<?= htmlspecialchars($company['email'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Website</label>
                            <input type="text" name="website" class="fw-qi__input" value="<?= htmlspecialchars($company['website'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="fw-qi__form-section">
                        <h3 class="fw-qi__form-section-title">Address</h3>
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Address Line 1</label>
                            <input type="text" name="address_line1" class="fw-qi__input" value="<?= htmlspecialchars($company['address_line1'] ?? '') ?>">
                        </div>
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Address Line 2</label>
                            <input type="text" name="address_line2" class="fw-qi__input" value="<?= htmlspecialchars($company['address_line2'] ?? '') ?>">
                        </div>
                        <div class="fw-qi__form-row">
                            <div class="fw-qi__form-group">
                                <label class="fw-qi__label">City</label>
                                <input type="text" name="city" class="fw-qi__input" value="<?= htmlspecialchars($company['city'] ?? '') ?>">
                            </div>
                            <div class="fw-qi__form-group">
                                <label class="fw-qi__label">Region</label>
                                <input type="text" name="region" class="fw-qi__input" value="<?= htmlspecialchars($company['region'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Postal Code</label>
                            <input type="text" name="postal" class="fw-qi__input" value="<?= htmlspecialchars($company['postal'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="fw-qi__form-actions">
                        <button type="submit" class="fw-qi__btn fw-qi__btn--primary">Save Company Details</button>
                    </div>
                </form>
            </div>

            <!-- BANKING TAB -->
            <div class="fw-qi__settings-panel" data-panel="banking">
                <form method="POST" class="fw-qi__settings-form">
                    <input type="hidden" name="action" value="update_banking">
                    <div class="fw-qi__form-section">
                        <h3 class="fw-qi__form-section-title">Banking Details</h3>
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Bank Name</label>
                            <input type="text" name="bank_name" class="fw-qi__input" value="<?= htmlspecialchars($company['bank_name'] ?? '') ?>">
                        </div>
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Account Number</label>
                            <input type="text" name="bank_account_number" class="fw-qi__input" value="<?= htmlspecialchars($company['bank_account_number'] ?? '') ?>">
                        </div>
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Branch Code</label>
                            <input type="text" name="bank_branch_code" class="fw-qi__input" value="<?= htmlspecialchars($company['bank_branch_code'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="fw-qi__form-actions">
                        <button type="submit" class="fw-qi__btn fw-qi__btn--primary">Save Banking</button>
                    </div>
                </form>
            </div>

            <!-- BRANDING TAB -->
            <div class="fw-qi__settings-panel" data-panel="branding">
                <form method="POST" class="fw-qi__settings-form">
                    <input type="hidden" name="action" value="update_branding">
                    
                    <div class="fw-qi__form-section">
                        <h3 class="fw-qi__form-section-title">Template Style</h3>
                        <p class="fw-qi__help-text">Choose your document layout (more coming soon)</p>
                        
                        <div class="fw-qi__template-grid">
                            <label class="fw-qi__template-card">
                                <input type="radio" name="qi_template" value="modern" checked>
                                <div class="fw-qi__template-preview fw-qi__template-preview--modern">
                                    <div class="fw-qi__template-preview-header">Modern</div>
                                    <div class="fw-qi__template-preview-body">
                                        <div class="fw-qi__template-preview-line"></div>
                                        <div class="fw-qi__template-preview-line fw-qi__template-preview-line--short"></div>
                                    </div>
                                </div>
                                <div class="fw-qi__template-name">Modern (Active)</div>
                            </label>

                            <label class="fw-qi__template-card fw-qi__template-card--disabled">
                                <input type="radio" name="qi_template" value="classic" disabled>
                                <div class="fw-qi__template-preview fw-qi__template-preview--classic">
                                    <div class="fw-qi__template-preview-header">Classic</div>
                                    <div class="fw-qi__template-preview-body">
                                        <div class="fw-qi__template-preview-line"></div>
                                        <div class="fw-qi__template-preview-line"></div>
                                    </div>
                                </div>
                                <div class="fw-qi__template-name">Classic (Soon)</div>
                            </label>

                            <label class="fw-qi__template-card fw-qi__template-card--disabled">
                                <input type="radio" name="qi_template" value="minimal" disabled>
                                <div class="fw-qi__template-preview fw-qi__template-preview--minimal">
                                    <div class="fw-qi__template-preview-header">Minimal</div>
                                    <div class="fw-qi__template-preview-body">
                                        <div class="fw-qi__template-preview-line fw-qi__template-preview-line--short"></div>
                                    </div>
                                </div>
                                <div class="fw-qi__template-name">Minimal (Soon)</div>
                            </label>

                            <label class="fw-qi__template-card fw-qi__template-card--disabled">
                                <input type="radio" name="qi_template" value="bold" disabled>
                                <div class="fw-qi__template-preview fw-qi__template-preview--bold">
                                    <div class="fw-qi__template-preview-stripe"></div>
                                    <div class="fw-qi__template-preview-header">Bold</div>
                                    <div class="fw-qi__template-preview-body">
                                        <div class="fw-qi__template-preview-line"></div>
                                    </div>
                                </div>
                                <div class="fw-qi__template-name">Bold (Soon)</div>
                            </label>

                            <label class="fw-qi__template-card fw-qi__template-card--disabled">
                                <input type="radio" name="qi_template" value="corporate" disabled>
                                <div class="fw-qi__template-preview fw-qi__template-preview--corporate">
                                    <div class="fw-qi__template-preview-header">Corporate</div>
                                    <div class="fw-qi__template-preview-body">
                                        <div class="fw-qi__template-preview-line"></div>
                                        <div class="fw-qi__template-preview-line"></div>
                                    </div>
                                </div>
                                <div class="fw-qi__template-name">Corporate (Soon)</div>
                            </label>
                        </div>
                    </div>

                    <div class="fw-qi__form-section">
                        <h3 class="fw-qi__form-section-title">Font</h3>
                        <div class="fw-qi__font-grid">
                            <label class="fw-qi__font-card">
                                <input type="radio" name="qi_font_family" value="system-ui" <?= ($company['qi_font_family'] ?? 'system-ui') === 'system-ui' ? 'checked' : '' ?>>
                                <div class="fw-qi__font-preview" style="font-family: system-ui;">
                                    <div class="fw-qi__font-sample">Aa</div>
                                    <div class="fw-qi__font-name">System UI</div>
                                </div>
                            </label>
                            <label class="fw-qi__font-card">
                                <input type="radio" name="qi_font_family" value="montserrat" <?= ($company['qi_font_family'] ?? '') === 'montserrat' ? 'checked' : '' ?>>
                                <div class="fw-qi__font-preview" style="font-family: 'Montserrat';">
                                    <div class="fw-qi__font-sample">Aa</div>
                                    <div class="fw-qi__font-name">Montserrat</div>
                                </div>
                            </label>
                            <label class="fw-qi__font-card">
                                <input type="radio" name="qi_font_family" value="helvetica" <?= ($company['qi_font_family'] ?? '') === 'helvetica' ? 'checked' : '' ?>>
                                <div class="fw-qi__font-preview" style="font-family: Helvetica;">
                                    <div class="fw-qi__font-sample">Aa</div>
                                    <div class="fw-qi__font-name">Helvetica</div>
                                </div>
                            </label>
                            <label class="fw-qi__font-card">
                                <input type="radio" name="qi_font_family" value="georgia" <?= ($company['qi_font_family'] ?? '') === 'georgia' ? 'checked' : '' ?>>
                                <div class="fw-qi__font-preview" style="font-family: Georgia;">
                                    <div class="fw-qi__font-sample">Aa</div>
                                    <div class="fw-qi__font-name">Georgia</div>
                                </div>
                            </label>
                            <label class="fw-qi__font-card">
                                <input type="radio" name="qi_font_family" value="inter" <?= ($company['qi_font_family'] ?? '') === 'inter' ? 'checked' : '' ?>>
                                <div class="fw-qi__font-preview" style="font-family: 'Inter';">
                                    <div class="fw-qi__font-sample">Aa</div>
                                    <div class="fw-qi__font-name">Inter</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="fw-qi__form-section">
                        <h3 class="fw-qi__form-section-title">Colors</h3>
                        <div class="fw-qi__form-row">
                            <div class="fw-qi__form-group">
                                <label class="fw-qi__label">Primary Color</label>
                                <div class="fw-qi__color-input-wrapper">
                                    <input type="color" name="primary_color" class="fw-qi__color-picker" value="<?= htmlspecialchars($company['primary_color'] ?? '#fbbf24') ?>" id="primaryColor">
                                    <input type="text" class="fw-qi__input fw-qi__input--color" value="<?= htmlspecialchars($company['primary_color'] ?? '#fbbf24') ?>" readonly id="primaryColorText">
                                </div>
                            </div>
                            <div class="fw-qi__form-group">
                                <label class="fw-qi__label">Secondary Color</label>
                                <div class="fw-qi__color-input-wrapper">
                                    <input type="color" name="secondary_color" class="fw-qi__color-picker" value="<?= htmlspecialchars($company['secondary_color'] ?? '#f59e0b') ?>" id="secondaryColor">
                                    <input type="text" class="fw-qi__input fw-qi__input--color" value="<?= htmlspecialchars($company['secondary_color'] ?? '#f59e0b') ?>" readonly id="secondaryColorText">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="fw-qi__form-section">
                        <h3 class="fw-qi__form-section-title">Show on Documents</h3>
                        <div class="fw-qi__checkbox-grid">
                            <label class="fw-qi__checkbox-card">
                                <input type="checkbox" name="qi_show_company_address" value="1" <?= ($company['qi_show_company_address'] ?? 1) ? 'checked' : '' ?>>
                                <div class="fw-qi__checkbox-content">
                                    <div class="fw-qi__checkbox-icon">📍</div>
                                    <div class="fw-qi__checkbox-label">Address</div>
                                </div>
                            </label>
                            <label class="fw-qi__checkbox-card">
                                <input type="checkbox" name="qi_show_company_phone" value="1" <?= ($company['qi_show_company_phone'] ?? 1) ? 'checked' : '' ?>>
                                <div class="fw-qi__checkbox-content">
                                    <div class="fw-qi__checkbox-icon">📞</div>
                                    <div class="fw-qi__checkbox-label">Phone</div>
                                </div>
                            </label>
                            <label class="fw-qi__checkbox-card">
                                <input type="checkbox" name="qi_show_company_email" value="1" <?= ($company['qi_show_company_email'] ?? 1) ? 'checked' : '' ?>>
                                <div class="fw-qi__checkbox-content">
                                    <div class="fw-qi__checkbox-icon">📧</div>
                                    <div class="fw-qi__checkbox-label">Email</div>
                                </div>
                            </label>
                            <label class="fw-qi__checkbox-card">
                                <input type="checkbox" name="qi_show_company_website" value="1" <?= ($company['qi_show_company_website'] ?? 1) ? 'checked' : '' ?>>
                                <div class="fw-qi__checkbox-content">
                                    <div class="fw-qi__checkbox-icon">🌐</div>
                                    <div class="fw-qi__checkbox-label">Website</div>
                                </div>
                            </label>
                            <label class="fw-qi__checkbox-card">
                                <input type="checkbox" name="qi_show_vat_number" value="1" <?= ($company['qi_show_vat_number'] ?? 1) ? 'checked' : '' ?>>
                                <div class="fw-qi__checkbox-content">
                                    <div class="fw-qi__checkbox-icon">🔢</div>
                                    <div class="fw-qi__checkbox-label">VAT Number</div>
                                </div>
                            </label>
                            <label class="fw-qi__checkbox-card">
                                <input type="checkbox" name="qi_show_tax_number" value="1" <?= ($company['qi_show_tax_number'] ?? 1) ? 'checked' : '' ?>>
                                <div class="fw-qi__checkbox-content">
                                    <div class="fw-qi__checkbox-icon">💼</div>
                                    <div class="fw-qi__checkbox-label">Tax Number</div>
                                </div>
                            </label>
                            <label class="fw-qi__checkbox-card">
                                <input type="checkbox" name="qi_show_reg_number" value="1" <?= ($company['qi_show_reg_number'] ?? 1) ? 'checked' : '' ?>>
                                <div class="fw-qi__checkbox-content">
                                    <div class="fw-qi__checkbox-icon">📋</div>
                                    <div class="fw-qi__checkbox-label">Reg Number</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="fw-qi__form-section">
                        <h3 class="fw-qi__form-section-title">Footers</h3>
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Quote Footer</label>
                            <textarea name="quote_footer_text" class="fw-qi__textarea" rows="3"><?= htmlspecialchars($company['quote_footer_text'] ?? '') ?></textarea>
                        </div>
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Invoice Footer</label>
                            <textarea name="invoice_footer_text" class="fw-qi__textarea" rows="3"><?= htmlspecialchars($company['invoice_footer_text'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="fw-qi__form-actions">
                        <button type="submit" class="fw-qi__btn fw-qi__btn--primary">Save Branding</button>
                        <a href="/qi/quote_view.php?id=1" target="_blank" class="fw-qi__btn fw-qi__btn--secondary">Preview Quote</a>
                        <a href="/qi/invoice_view.php?id=1" target="_blank" class="fw-qi__btn fw-qi__btn--secondary">Preview Invoice</a>
                    </div>
                </form>
            </div>

            <!-- LOGO TAB -->
            <div class="fw-qi__settings-panel" data-panel="logo">
                <div class="fw-qi__settings-form">
                    <div class="fw-qi__form-section">
                        <h3 class="fw-qi__form-section-title">Logo</h3>
                        <?php if ($company['logo_url']): ?>
                            <div class="fw-qi__logo-preview">
                                <img src="<?= htmlspecialchars($company['logo_url']) ?>" alt="Logo" id="currentLogo">
                                <button type="button" class="fw-qi__btn fw-qi__btn--secondary" onclick="QISettings.removeLogo()">Remove</button>
                            </div>
                        <?php else: ?>
                            <div class="fw-qi__logo-placeholder">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                                <p>No logo</p>
                            </div>
                        <?php endif; ?>
                        <input type="file" id="logoUpload" accept="image/png,image/jpeg,image/jpg" style="display:none;">
                        <button type="button" class="fw-qi__btn fw-qi__btn--primary" onclick="document.getElementById('logoUpload').click()">Choose Logo</button>
                        <span id="uploadStatus" style="margin-left:12px;font-size:14px;font-weight:600;"></span>
                    </div>
                </div>
            </div>

        </main>

        <footer class="fw-qi__footer">
            <span>Q&I v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>

    </div>

    <script src="/qi/assets/qi.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
        // Tab switching
        document.querySelectorAll('.fw-qi__settings-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                const targetPanel = tab.dataset.tab;
                document.querySelectorAll('.fw-qi__settings-tab').forEach(t => t.classList.remove('fw-qi__settings-tab--active'));
                tab.classList.add('fw-qi__settings-tab--active');
                document.querySelectorAll('.fw-qi__settings-panel').forEach(p => p.classList.remove('fw-qi__settings-panel--active'));
                document.querySelector(`[data-panel="${targetPanel}"]`).classList.add('fw-qi__settings-panel--active');
            });
        });

        // Color picker sync
        document.getElementById('primaryColor').addEventListener('input', (e) => {
            document.getElementById('primaryColorText').value = e.target.value;
        });
        document.getElementById('secondaryColor').addEventListener('input', (e) => {
            document.getElementById('secondaryColorText').value = e.target.value;
        });

        // Logo upload
        document.getElementById('logoUpload').addEventListener('change', async (e) => {
            const file = e.target.files[0];
            if (!file) return;
            const status = document.getElementById('uploadStatus');
            status.textContent = 'Uploading...';
            status.style.color = 'var(--accent-qi)';
            const formData = new FormData();
            formData.append('logo', file);
            formData.append('company_id', <?= $companyId ?>);
            try {
                const res = await fetch('/qi/ajax/upload_logo.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.ok) {
                    status.textContent = 'Uploaded!';
                    status.style.color = '#10b981';
                    setTimeout(() => location.reload(), 1000);
                } else {
                    status.textContent = 'Error: ' + (data.error || 'Failed');
                    status.style.color = '#ef4444';
                }
            } catch (err) {
                status.textContent = 'Network error';
                status.style.color = '#ef4444';
            }
        });

        window.QISettings = {
            async removeLogo() {
                if (!confirm('Remove logo?')) return;
                try {
                    const res = await fetch('/qi/ajax/remove_logo.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({company_id: <?= $companyId ?>})
                    });
                    const data = await res.json();
                    if (data.ok) { alert('Removed!'); location.reload(); }
                    else { alert('Error: ' + (data.error || 'Failed')); }
                } catch (err) { alert('Network error'); }
            }
        };
    </script>
</body>
</html>