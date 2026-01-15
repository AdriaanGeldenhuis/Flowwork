<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-RECEIPTS-1');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];
// Determine the user's role from the database. Do not rely solely on session role as it may be unset.
$stmtRole = $DB->prepare("SELECT role FROM users WHERE id = ? AND company_id = ? LIMIT 1");
$stmtRole->execute([$userId, $companyId]);
$userRole = $stmtRole->fetchColumn();
if (!$userRole) {
    $userRole = 'member';
}

// Only certain roles can edit settings. Align with CRM settings by allowing owners as well.
if (!in_array($userRole, ['admin', 'bookkeeper', 'owner'])) {
    header('Location: index.php');
    exit;
}

// Fetch settings
$stmt = $DB->prepare("SELECT * FROM receipt_settings WHERE company_id = ?");
$stmt->execute([$companyId]);
$settings = $stmt->fetch();

if (!$settings) {
    // Create default settings
    $stmt = $DB->prepare("
        INSERT INTO receipt_settings (company_id) VALUES (?)
    ");
    $stmt->execute([$companyId]);
    $settings = [
        'company_id' => $companyId,
        'require_po_over_amount' => 5000.00,
        'require_3way_match' => 0,
        'variance_tolerance_pct' => 5.00,
        'line_variance_tolerance_pct' => 10.00,
        'require_vat_number' => 1,
        'block_expired_compliance' => 1,
        'default_expense_account' => '5000',
        'max_file_size_mb' => 15,
        'ocr_provider' => 'tesseract',
        'auto_split_lines' => 1,
        'retention_days' => 2555
    ];
}

// Handle form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $updateStmt = $DB->prepare("
        UPDATE receipt_settings SET
            require_po_over_amount = ?,
            require_3way_match = ?,
            variance_tolerance_pct = ?,
            line_variance_tolerance_pct = ?,
            require_vat_number = ?,
            block_expired_compliance = ?,
            default_expense_account = ?,
            max_file_size_mb = ?,
            ocr_provider = ?,
            auto_split_lines = ?,
            retention_days = ?
        WHERE company_id = ?
    ");
    
    $result = $updateStmt->execute([
        $_POST['require_po_over_amount'] ?? 5000,
        isset($_POST['require_3way_match']) ? 1 : 0,
        $_POST['variance_tolerance_pct'] ?? 5,
        $_POST['line_variance_tolerance_pct'] ?? 10,
        isset($_POST['require_vat_number']) ? 1 : 0,
        isset($_POST['block_expired_compliance']) ? 1 : 0,
        $_POST['default_expense_account'] ?? '5000',
        $_POST['max_file_size_mb'] ?? 15,
        $_POST['ocr_provider'] ?? 'tesseract',
        isset($_POST['auto_split_lines']) ? 1 : 0,
        $_POST['retention_days'] ?? 2555,
        $companyId
    ]);

    if ($result) {
        $message = 'Settings saved successfully';
        $messageType = 'success';
        
        // Reload settings
        $stmt = $DB->prepare("SELECT * FROM receipt_settings WHERE company_id = ?");
        $stmt->execute([$companyId]);
        $settings = $stmt->fetch();
    } else {
        $message = 'Error saving settings';
        $messageType = 'error';
    }
}

// Handle reset AI mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_ai_map'])) {
    try {
        // Ensure company_settings entry exists; upsert resets value to empty JSON
        $stmt = $DB->prepare("
            INSERT INTO company_settings (company_id, setting_key, setting_value, updated_at)
            VALUES (?, 'receipts_ai_map', '', NOW())
            ON DUPLICATE KEY UPDATE setting_value = '', updated_at = NOW()
        ");
        $stmt->execute([$companyId]);
        $message = 'AI mapping reset successfully';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Failed to reset AI mapping';
        $messageType = 'error';
    }
    // No need to reload settings as it does not affect settings table
}

// Fetch company
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Fetch user
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt Settings – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="assets/receipts.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-receipts">
    <div class="fw-receipts__container">
        
        <!-- Header -->
        <header class="fw-receipts__header">
            <div class="fw-receipts__brand">
                <div class="fw-receipts__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="fw-receipts__brand-text">
                    <div class="fw-receipts__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-receipts__app-name">Settings</div>
                </div>
            </div>

            <div class="fw-receipts__greeting">
                Hello, <span class="fw-receipts__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>

            <div class="fw-receipts__controls">
                <a href="/" class="fw-receipts__home-btn" title="Home">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2"/>
                        <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2"/>
                    </svg>
                </a>
                
                <button class="fw-receipts__theme-toggle" id="themeToggle">
                    <svg class="fw-receipts__theme-icon fw-receipts__theme-icon--light" viewBox="0 0 24 24" fill="none">
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
                    <svg class="fw-receipts__theme-icon fw-receipts__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2"/>
                    </svg>
                </button>

                <a href="index.php" class="fw-receipts__btn fw-receipts__btn--secondary">Back to Receipts</a>
            </div>
        </header>

        <!-- Main Content -->
        <main class="fw-receipts__main">
            
            <div class="fw-receipts__page-header">
                <h1 class="fw-receipts__page-title">Receipt Settings</h1>
                <p class="fw-receipts__page-subtitle">Configure policies, tolerances & OCR settings</p>
            </div>

            <?php if ($message): ?>
            <div class="fw-receipts__alert fw-receipts__alert--<?= $messageType ?>">
                <?= htmlspecialchars($message) ?>
            </div>
            <?php endif; ?>

            <form method="POST" class="fw-receipts__settings-form">
                <div class="fw-receipts__form">

                    <!-- Policy Rules -->
                    <div class="fw-receipts__form-section">
                        <h3 class="fw-receipts__form-section-title">Policy Rules</h3>
                        
                        <div class="fw-receipts__form-group">
                            <label class="fw-receipts__label">Require PO over amount (ZAR)</label>
                            <input type="number" step="0.01" name="require_po_over_amount" class="fw-receipts__input" 
                                   value="<?= number_format($settings['require_po_over_amount'], 2, '.', '') ?>">
                            <small class="fw-receipts__help-text">Invoices above this amount require a PO reference</small>
                        </div>

                        <div class="fw-receipts__form-group">
                            <label class="fw-receipts__checkbox-wrapper">
                                <input type="checkbox" name="require_3way_match" class="fw-receipts__checkbox" 
                                       <?= $settings['require_3way_match'] ? 'checked' : '' ?>>
                                Require 3-way match (PO + GRN + Invoice)
                            </label>
                        </div>

                        <div class="fw-receipts__form-group">
                            <label class="fw-receipts__checkbox-wrapper">
                                <input type="checkbox" name="require_vat_number" class="fw-receipts__checkbox" 
                                       <?= $settings['require_vat_number'] ? 'checked' : '' ?>>
                                Require VAT number when tax > 0
                            </label>
                        </div>

                        <div class="fw-receipts__form-group">
                            <label class="fw-receipts__checkbox-wrapper">
                                <input type="checkbox" name="block_expired_compliance" class="fw-receipts__checkbox" 
                                       <?= $settings['block_expired_compliance'] ? 'checked' : '' ?>>
                                Block posting if supplier compliance expired
                            </label>
                        </div>
                    </div>

                    <!-- Matching Tolerances -->
                    <div class="fw-receipts__form-section">
                        <h3 class="fw-receipts__form-section-title">Matching Tolerances</h3>
                        
                        <div class="fw-receipts__form-row">
                            <div class="fw-receipts__form-group">
                                <label class="fw-receipts__label">Header variance tolerance (%)</label>
                                <input type="number" step="0.01" name="variance_tolerance_pct" class="fw-receipts__input" 
                                       value="<?= number_format($settings['variance_tolerance_pct'], 2, '.', '') ?>">
                                <small class="fw-receipts__help-text">Allow invoice total vs PO variance</small>
                            </div>

                            <div class="fw-receipts__form-group">
                                <label class="fw-receipts__label">Line variance tolerance (%)</label>
                                <input type="number" step="0.01" name="line_variance_tolerance_pct" class="fw-receipts__input" 
                                       value="<?= number_format($settings['line_variance_tolerance_pct'], 2, '.', '') ?>">
                                <small class="fw-receipts__help-text">Allow line-level price/qty variance</small>
                            </div>
                        </div>
                    </div>

                    <!-- OCR Settings -->
                    <div class="fw-receipts__form-section">
                        <h3 class="fw-receipts__form-section-title">OCR & Processing</h3>
                        
                        <div class="fw-receipts__form-group">
                            <label class="fw-receipts__label">OCR Provider</label>
                            <select name="ocr_provider" class="fw-receipts__input">
                                <option value="tesseract" <?= $settings['ocr_provider'] === 'tesseract' ? 'selected' : '' ?>>Tesseract (Local)</option>
                                <option value="google" <?= $settings['ocr_provider'] === 'google' ? 'selected' : '' ?>>Google Vision</option>
                                <option value="aws" <?= $settings['ocr_provider'] === 'aws' ? 'selected' : '' ?>>AWS Textract</option>
                                <option value="azure" <?= $settings['ocr_provider'] === 'azure' ? 'selected' : '' ?>>Azure Form Recognizer</option>
                            </select>
                        </div>

                        <div class="fw-receipts__form-group">
                            <label class="fw-receipts__checkbox-wrapper">
                                <input type="checkbox" name="auto_split_lines" class="fw-receipts__checkbox" 
                                       <?= $settings['auto_split_lines'] ? 'checked' : '' ?>>
                                Auto-split line items from OCR
                            </label>
                        </div>
                    </div>

                    <!-- File Settings -->
                    <div class="fw-receipts__form-section">
                        <h3 class="fw-receipts__form-section-title">File Management</h3>
                        
                        <div class="fw-receipts__form-row">
                            <div class="fw-receipts__form-group">
                                <label class="fw-receipts__label">Max file size (MB)</label>
                                <input type="number" name="max_file_size_mb" class="fw-receipts__input" 
                                       value="<?= (int)$settings['max_file_size_mb'] ?>">
                            </div>

                            <div class="fw-receipts__form-group">
                                <label class="fw-receipts__label">Retention period (days)</label>
                                <input type="number" name="retention_days" class="fw-receipts__input" 
                                       value="<?= (int)$settings['retention_days'] ?>">
                                <small class="fw-receipts__help-text">0 = keep forever</small>
                            </div>
                        </div>
                    </div>

                    <!-- Default Accounts -->
                    <div class="fw-receipts__form-section">
                        <h3 class="fw-receipts__form-section-title">Default GL Accounts</h3>
                        
                        <div class="fw-receipts__form-group">
                            <label class="fw-receipts__label">Default expense account</label>
                            <input type="text" name="default_expense_account" class="fw-receipts__input" 
                                   value="<?= htmlspecialchars($settings['default_expense_account']) ?>" placeholder="e.g. 5000">
                            <small class="fw-receipts__help-text">Used when no category is specified</small>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="fw-receipts__form-actions">
                        <a href="index.php" class="fw-receipts__btn fw-receipts__btn--secondary">Cancel</a>
                        <button type="submit" name="save_settings" class="fw-receipts__btn fw-receipts__btn--primary">Save Settings</button>
                        <!-- Utility buttons -->
                        <button type="button" id="runOCRTest" class="fw-receipts__btn fw-receipts__btn--secondary">Run OCR Test</button>
                        <button type="submit" name="reset_ai_map" class="fw-receipts__btn fw-receipts__btn--secondary">Reset AI Mapping</button>
                    </div>

                    <!-- Hidden file input and result container for OCR test -->
                    <input type="file" id="ocrTestFile" accept="image/*,application/pdf" style="display:none">
                    <pre id="ocrTestResult" class="fw-receipts__ocr-result" style="margin-top:1rem; overflow-x:auto;"></pre>

                </div>
            </form>

        </main>

        <!-- Footer -->
        <footer class="fw-receipts__footer">
            <span>Receipts v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>

    </div>

    <script src="assets/receipts.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
    // OCR Test handler
    (function() {
        const runBtn = document.getElementById('runOCRTest');
        const fileInput = document.getElementById('ocrTestFile');
        const resultEl = document.getElementById('ocrTestResult');
        if (!runBtn || !fileInput) return;
        runBtn.addEventListener('click', function() {
            fileInput.click();
        });
        fileInput.addEventListener('change', function(e) {
            const file = this.files && this.files[0];
            if (!file) return;
            resultEl.textContent = 'Uploading...';
            const formData = new FormData();
            formData.append('file', file);
            // Upload file
            fetch('api/upload_start.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (!data || !data.file_id) {
                    resultEl.textContent = 'Upload failed';
                    return;
                }
                const fileId = data.file_id;
                // Trigger OCR
                fetch('api/ocr_trigger.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ file_id: fileId })
                }).then(() => {
                    resultEl.textContent = 'Processing...';
                    // Poll for OCR result
                    const poll = setInterval(() => {
                        fetch('api/ocr_poll.php?file_id=' + fileId)
                        .then(r => r.json())
                        .then(statusData => {
                            if (statusData.status === 'parsed') {
                                clearInterval(poll);
                                // Show parsed OCR JSON
                                resultEl.textContent = JSON.stringify(statusData.ocr, null, 2);
                            } else if (statusData.status === 'failed') {
                                clearInterval(poll);
                                resultEl.textContent = 'OCR failed';
                            }
                        })
                        .catch(() => {
                            clearInterval(poll);
                            resultEl.textContent = 'Error polling OCR';
                        });
                    }, 1500);
                });
            })
            .catch(() => {
                resultEl.textContent = 'Network error';
            });
        });
    })();
    </script>
</body>
</html>