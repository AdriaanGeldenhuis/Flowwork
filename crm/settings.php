<?php
// /crm/settings.php - COMPLETE SETTINGS PAGE
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-CRM-4');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Check if user has admin rights
$stmt = $DB->prepare("SELECT role FROM users WHERE id = ? AND company_id = ?");
$stmt->execute([$userId, $companyId]);
$userRole = $stmt->fetchColumn();

if (!in_array($userRole, ['admin', 'owner'])) {
    header('Location: /crm/');
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

// Fetch current settings
$settingsStmt = $DB->prepare("SELECT setting_key, setting_value FROM company_settings WHERE company_id = ?");
$settingsStmt->execute([$companyId]);
$settingsRaw = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Default settings
$settings = [
    'crm_default_status' => $settingsRaw['crm_default_status'] ?? 'active',
    'crm_enable_duplicate_check' => $settingsRaw['crm_enable_duplicate_check'] ?? '1',
    'crm_duplicate_threshold' => $settingsRaw['crm_duplicate_threshold'] ?? '0.85',
    // Timeline limit determines how many events show on the account timeline (default 50)
    'crm_timeline_limit' => $settingsRaw['crm_timeline_limit'] ?? '50',
    // Toggle compliance badge display on account pages
    'crm_compliance_badge_enable' => $settingsRaw['crm_compliance_badge_enable'] ?? '1',
    'crm_phone_format' => $settingsRaw['crm_phone_format'] ?? 'international',
    'crm_require_vat' => $settingsRaw['crm_require_vat'] ?? '0',
    'crm_enable_tags' => $settingsRaw['crm_enable_tags'] ?? '1'
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    try {
        $DB->beginTransaction();

        $newSettings = [
            'crm_default_status' => $_POST['crm_default_status'] ?? 'active',
            'crm_enable_duplicate_check' => isset($_POST['crm_enable_duplicate_check']) ? '1' : '0',
            // similarity threshold for duplicate detection; clamp between 0.70 and 0.95
            'crm_duplicate_threshold' => floatval($_POST['crm_duplicate_threshold'] ?? 0.85),
            'crm_phone_format' => $_POST['crm_phone_format'] ?? 'international',
            'crm_require_vat' => isset($_POST['crm_require_vat']) ? '1' : '0',
            'crm_enable_tags' => isset($_POST['crm_enable_tags']) ? '1' : '0',
            // timeline limit number of events; default 50
            'crm_timeline_limit' => isset($_POST['crm_timeline_limit']) ? intval($_POST['crm_timeline_limit']) : 50,
            // compliance badge toggle
            'crm_compliance_badge_enable' => isset($_POST['crm_compliance_badge_enable']) ? '1' : '0'
        ];

        foreach ($newSettings as $key => $value) {
            $stmt = $DB->prepare("
                INSERT INTO company_settings (company_id, setting_key, setting_value, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
            ");
            $stmt->execute([$companyId, $key, $value, $value]);
        }

        $DB->commit();
        $successMessage = 'Settings saved successfully!';
        $settings = $newSettings;

    } catch (Exception $e) {
        if ($DB->inTransaction()) {
            $DB->rollBack();
        }
        $errorMessage = 'Failed to save settings: ' . $e->getMessage();
    }
}

// Fetch compliance types
$complianceTypes = $DB->prepare("SELECT * FROM crm_compliance_types WHERE company_id = ? ORDER BY name");
$complianceTypes->execute([$companyId]);
$types = $complianceTypes->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM Settings – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/crm/assets/crm.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-crm">
    <div class="fw-crm__container">
        
        <!-- Header -->
        <header class="fw-crm__header">
            <div class="fw-crm__brand">
                <div class="fw-crm__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="fw-crm__brand-text">
                    <div class="fw-crm__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-crm__app-name">CRM – Settings</div>
                </div>
            </div>

            <div class="fw-crm__greeting">
                Hello, <span class="fw-crm__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>

            <div class="fw-crm__controls">
                <a href="/crm/" class="fw-crm__back-btn" title="Back to CRM">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                
                <a href="/" class="fw-crm__home-btn" title="Home">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                
                <button class="fw-crm__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                    <svg class="fw-crm__theme-icon fw-crm__theme-icon--light" viewBox="0 0 24 24" fill="none">
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
                    <svg class="fw-crm__theme-icon fw-crm__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <div class="fw-crm__menu-wrapper">
                    <button class="fw-crm__kebab-toggle" id="kebabToggle" aria-label="Menu">
                        <svg viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                        </svg>
                    </button>
                    <nav class="fw-crm__kebab-menu" id="kebabMenu" aria-hidden="true">
                        <a href="/crm/" class="fw-crm__kebab-item">Back to CRM</a>
                        <a href="/crm/import.php" class="fw-crm__kebab-item">Import/Export</a>
                        <a href="/crm/dedupe.php" class="fw-crm__kebab-item">Dedupe & Merge</a>
                    </nav>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="fw-crm__main">
            
            <div class="fw-crm__page-header">
                <h1 class="fw-crm__page-title">CRM Settings</h1>
                <p class="fw-crm__page-subtitle">
                    Configure your CRM preferences and compliance types
                </p>
            </div>

            <?php if (isset($successMessage)): ?>
                <div class="fw-crm__alert fw-crm__alert--success">
                    ✓ <?= htmlspecialchars($successMessage) ?>
                </div>
            <?php endif; ?>

            <?php if (isset($errorMessage)): ?>
                <div class="fw-crm__alert fw-crm__alert--error">
                    ✗ <?= htmlspecialchars($errorMessage) ?>
                </div>
            <?php endif; ?>

            <!-- Settings Form -->
            <form method="POST" class="fw-crm__form">
                
                <!-- General Settings -->
                <div class="fw-crm__form-card">
                    <h2 class="fw-crm__form-card-title">General Settings</h2>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Default Account Status</label>
                        <select name="crm_default_status" class="fw-crm__input">
                            <option value="active" <?= $settings['crm_default_status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $settings['crm_default_status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            <option value="prospect" <?= $settings['crm_default_status'] === 'prospect' ? 'selected' : '' ?>>Prospect</option>
                        </select>
                        <small class="fw-crm__help-text">Status assigned to new accounts by default</small>
                    </div>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Phone Number Format</label>
                        <select name="crm_phone_format" class="fw-crm__input">
                            <option value="international" <?= $settings['crm_phone_format'] === 'international' ? 'selected' : '' ?>>International (+27...)</option>
                            <option value="national" <?= $settings['crm_phone_format'] === 'national' ? 'selected' : '' ?>>National (012...)</option>
                        </select>
                        <small class="fw-crm__help-text">How phone numbers should be formatted</small>
                    </div>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__checkbox-wrapper">
                            <input type="checkbox" name="crm_require_vat" class="fw-crm__checkbox" <?= $settings['crm_require_vat'] === '1' ? 'checked' : '' ?>>
                            <span>Require VAT Number for Suppliers</span>
                        </label>
                        <small class="fw-crm__help-text">Make VAT number mandatory when creating suppliers</small>
                    </div>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__checkbox-wrapper">
                            <input type="checkbox" name="crm_enable_tags" class="fw-crm__checkbox" <?= $settings['crm_enable_tags'] === '1' ? 'checked' : '' ?>>
                            <span>Enable Tags</span>
                        </label>
                        <small class="fw-crm__help-text">Allow tagging of accounts for categorization</small>
                    </div>
                </div>

                <!-- Duplicate Detection -->
                <div class="fw-crm__form-card">
                    <h2 class="fw-crm__form-card-title">Duplicate Detection</h2>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__checkbox-wrapper">
                            <input type="checkbox" name="crm_enable_duplicate_check" class="fw-crm__checkbox" <?= $settings['crm_enable_duplicate_check'] === '1' ? 'checked' : '' ?>>
                            <span>Enable Duplicate Detection</span>
                        </label>
                        <small class="fw-crm__help-text">Check for duplicate accounts when creating new ones</small>
                    </div>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Similarity Threshold</label>
                        <input type="number" name="crm_duplicate_threshold" class="fw-crm__input"
                               value="<?= htmlspecialchars($settings['crm_duplicate_threshold']) ?>"
                               min="0.70" max="0.95" step="0.01">
                        <small class="fw-crm__help-text">Higher = stricter matching (0.70 – 0.95, default: 0.85)</small>
                    </div>
                </div>

                <!-- Timeline & Compliance Settings -->
                <div class="fw-crm__form-card">
                    <h2 class="fw-crm__form-card-title">Timeline & Compliance</h2>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Timeline Limit</label>
                        <input type="number" name="crm_timeline_limit" class="fw-crm__input"
                               value="<?= htmlspecialchars($settings['crm_timeline_limit']) ?>"
                               min="10" max="500" step="1">
                        <small class="fw-crm__help-text">Number of events shown in the timeline (10–500, default: 50)</small>
                    </div>

                    <div class="fw-crm__form-group">
                        <label class="fw-crm__checkbox-wrapper">
                            <input type="checkbox" name="crm_compliance_badge_enable" class="fw-crm__checkbox"
                                   <?= $settings['crm_compliance_badge_enable'] === '1' ? 'checked' : '' ?>>
                            <span>Display Compliance Badge</span>
                        </label>
                        <small class="fw-crm__help-text">Show a compliance badge on account pages</small>
                    </div>
                </div>

                <!-- Compliance Types -->
                <div class="fw-crm__form-card">
                    <h2 class="fw-crm__form-card-title">Compliance Document Types</h2>
                    <p class="fw-crm__help-text" style="margin-bottom:var(--fw-spacing-md);">
                        Manage the types of compliance documents your suppliers/customers must provide.
                    </p>

                    <!-- New Type Button -->
                    <div class="fw-crm__form-group" style="margin-bottom:var(--fw-spacing-md);">
                        <button type="button" class="fw-crm__btn fw-crm__btn--primary" onclick="openCTModal()">
                            + New Type
                        </button>
                    </div>

                    <?php if (count($types) > 0): ?>
                        <div class="fw-crm__compliance-types-list">
                            <?php foreach ($types as $type): ?>
                            <div class="fw-crm__compliance-type-item">
                                <div class="fw-crm__compliance-type-info">
                                    <div class="fw-crm__compliance-type-name">
                                        <?= htmlspecialchars($type['name']) ?>
                                        <?php if ($type['required']): ?>
                                            <span class="fw-crm__badge fw-crm__badge--danger">Required</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="fw-crm__compliance-type-meta">
                                        Code: <?= htmlspecialchars($type['code']) ?> • 
                                        Expires: <?= $type['default_expiry_months'] ?> months
                                    </div>
                                </div>
                                <div class="fw-crm__compliance-type-actions">
                                    <button type="button" class="fw-crm__btn fw-crm__btn--small fw-crm__btn--secondary" onclick="editCTType(<?= $type['id'] ?>)">
                                        Edit
                                    </button>
                                    <button type="button" class="fw-crm__btn fw-crm__btn--small fw-crm__btn--danger" onclick="deleteCTType(<?= $type['id'] ?>)">
                                        Delete
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="fw-crm__empty-state">
                            No compliance types defined yet.<br>
                            <small>Run the SQL seed script to add default types.</small>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Save Button -->
                <div class="fw-crm__form-actions">
                    <a href="/crm/" class="fw-crm__btn fw-crm__btn--secondary">
                        Cancel
                    </a>
                    <button type="submit" name="save_settings" class="fw-crm__btn fw-crm__btn--primary">
                        Save Settings
                    </button>
                </div>

            </form>

        </main>

        <!-- Footer -->
        <footer class="fw-crm__footer">
            <span>CRM v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>

    </div>

    <!-- Compliance Type Modal -->
    <div class="fw-crm__modal-overlay" id="ctModal">
        <div class="fw-crm__modal">
            <div class="fw-crm__modal-header">
                <h2 class="fw-crm__modal-title" id="ctModalTitle">Add Document Type</h2>
                <button class="fw-crm__modal-close" onclick="CRMModal.close('ctModal')">
                    <svg viewBox="0 0 24 24" fill="none">
                        <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            <form id="ctForm">
                <input type="hidden" name="id" id="ctId" value="">
                <div class="fw-crm__modal-body">
                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">
                            Code <span class="fw-crm__required">*</span>
                        </label>
                        <input type="text" name="code" id="ctCode" class="fw-crm__input" required placeholder="e.g., COC" maxlength="32">
                        <small class="fw-crm__help-text">Unique identifier (uppercase, no spaces)</small>
                    </div>
                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">
                            Name <span class="fw-crm__required">*</span>
                        </label>
                        <input type="text" name="name" id="ctName" class="fw-crm__input" required placeholder="e.g., Certificate of Compliance">
                    </div>
                    <div class="fw-crm__form-group">
                        <label class="fw-crm__label">Default Expiry (months)</label>
                        <input type="number" name="default_expiry_months" id="ctExpiry" class="fw-crm__input" min="1" max="120" placeholder="12">
                        <small class="fw-crm__help-text">Leave blank if documents don't expire</small>
                    </div>
                    <div class="fw-crm__form-group">
                        <label class="fw-crm__checkbox-wrapper">
                            <input type="checkbox" name="required" id="ctRequired" class="fw-crm__checkbox" value="1">
                            <span>Mark as required for all suppliers</span>
                        </label>
                    </div>
                </div>
                <div class="fw-crm__modal-footer">
                    <button type="button" class="fw-crm__btn fw-crm__btn--secondary" onclick="CRMModal.close('ctModal')">Cancel</button>
                    <button type="submit" class="fw-crm__btn fw-crm__btn--primary" id="ctSubmitBtn">Save Type</button>
                </div>
            </form>
        </div>
    </div>

    <script src="/crm/assets/crm.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
    // === Compliance Type Management ===
    function openCTModal() {
        // Reset form
        document.getElementById('ctForm').reset();
        document.getElementById('ctId').value = '';
        document.getElementById('ctModalTitle').textContent = 'Add Document Type';
        CRMModal.open('ctModal');
    }

    function editCTType(id) {
        fetch('/crm/ajax/compliance_type_get.php?id=' + id)
            .then(res => res.json())
            .then(data => {
                if (data.ok) {
                    const t = data.type;
                    document.getElementById('ctId').value = t.id;
                    document.getElementById('ctCode').value = t.code;
                    document.getElementById('ctName').value = t.name;
                    document.getElementById('ctExpiry').value = t.default_expiry_months || '';
                    document.getElementById('ctRequired').checked = t.required == 1;
                    document.getElementById('ctModalTitle').textContent = 'Edit Document Type';
                    CRMModal.open('ctModal');
                } else {
                    alert(data.error || 'Failed to load document type');
                }
            })
            .catch(() => alert('Network error'));
    }

    function deleteCTType(id) {
        if (!confirm('Delete this document type? All associated documents will be unlinked.')) return;
        fetch('/crm/ajax/compliance_type_delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        })
        .then(res => res.json())
        .then(data => {
            if (data.ok) {
                location.reload();
            } else {
                alert(data.error || 'Failed to delete document type');
            }
        })
        .catch(() => alert('Network error'));
    }

    document.getElementById('ctForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const submitBtn = document.getElementById('ctSubmitBtn');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving...';
        const formData = new FormData(this);
        fetch('/crm/ajax/compliance_type_save.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.ok) {
                location.reload();
            } else {
                alert(data.error || 'Failed to save document type');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Save Type';
            }
        })
        .catch(() => {
            alert('Network error');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save Type';
        });
    });
    </script>
</body>
</html>