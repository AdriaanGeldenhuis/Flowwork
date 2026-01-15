<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-RECEIPTS-1');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];
$fileId = (int)($_GET['id'] ?? 0);

if (!$fileId) {
    header('Location: index.php');
    exit;
}

// Fetch file
$stmt = $DB->prepare("
    SELECT rf.*, ro.vendor_name, ro.vendor_vat, ro.invoice_number, ro.invoice_date,
           ro.currency, ro.subtotal, ro.tax, ro.total, ro.line_items_json, ro.confidence_score
    FROM receipt_file rf
    LEFT JOIN receipt_ocr ro ON ro.file_id = rf.file_id
    WHERE rf.file_id = ? AND rf.company_id = ?
");
$stmt->execute([$fileId, $companyId]);
$file = $stmt->fetch();

if (!$file) {
    header('Location: index.php');
    exit;
}

// Parse OCR if pending
if ($file['ocr_status'] === 'pending') {
    // Trigger OCR (could be async, but for demo we'll do sync)
    require_once __DIR__ . '/lib/ocr_engine.php';
    
    $stmt = $DB->prepare("SELECT ocr_provider FROM receipt_settings WHERE company_id = ?");
    $stmt->execute([$companyId]);
    $settings = $stmt->fetch();
    $provider = $settings['ocr_provider'] ?? 'tesseract';
    
    $ocr = new OCREngine($provider);
    $fullPath = __DIR__ . '/../' . $file['path'];
    
    if (file_exists($fullPath)) {
        $result = $ocr->parseReceipt($fullPath, $file['mime']);
        
        $stmt = $DB->prepare("
            INSERT INTO receipt_ocr (
                file_id, raw_json, vendor_name, vendor_vat, invoice_number, invoice_date,
                currency, subtotal, tax, total, line_items_json, confidence_score
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $fileId,
            json_encode($result),
            $result['vendor_name'],
            $result['vendor_vat'],
            $result['invoice_number'],
            $result['invoice_date'],
            $result['currency'],
            $result['subtotal'],
            $result['tax'],
            $result['total'],
            json_encode($result['lines']),
            $result['confidence']
        ]);
        
        $stmt = $DB->prepare("UPDATE receipt_file SET ocr_status = 'parsed' WHERE file_id = ?");
        $stmt->execute([$fileId]);
        
        // Reload
        header('Location: review.php?id=' . $fileId);
        exit;
    }
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

// Parse lines
$lines = json_decode($file['line_items_json'] ?? '[]', true) ?: [];

// Fetch suppliers for dropdown
$stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'supplier' ORDER BY name");
$stmt->execute([$companyId]);
$suppliers = $stmt->fetchAll();

// Fetch policy issues (will be displayed in step 3 if any)
$stmt = $DB->prepare("\n    SELECT code, message, severity \n    FROM receipt_policy_log \n    WHERE company_id = ? AND file_id = ? AND override_by IS NULL\n    ORDER BY severity DESC, created_at DESC\n");
$stmt->execute([$companyId, $fileId]);
$policyIssues = $stmt->fetchAll();

// Attempt to fetch costing boards for project selection
try {
    $stmt = $DB->prepare("\n        SELECT pb.board_id, pb.title\n        FROM project_boards pb\n        WHERE pb.company_id = ? AND (pb.board_type = 'cost' OR pb.board_type = 'costing' OR pb.title LIKE '%Cost%')\n        ORDER BY pb.title ASC\n    ");
    $stmt->execute([$companyId]);
    $costBoards = $stmt->fetchAll();
} catch (Exception $e) {
    $costBoards = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Receipt – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="assets/receipts.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-receipts">
    <div class="fw-receipts__container fw-receipts__container--wide">
        
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
                    <div class="fw-receipts__app-name">Review Receipt</div>
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

                <a href="index.php" class="fw-receipts__btn fw-receipts__btn--secondary">Back</a>
            </div>
        </header>

        <!-- Split View -->
        <main class="fw-receipts__split-view">
            
            <!-- Left: Document Viewer -->
            <div class="fw-receipts__viewer">
                <div class="fw-receipts__viewer-toolbar">
                    <button class="fw-receipts__viewer-btn" id="zoomOutBtn" title="Zoom Out">−</button>
                    <span id="zoomLevel">100%</span>
                    <button class="fw-receipts__viewer-btn" id="zoomInBtn" title="Zoom In">+</button>
                    <button class="fw-receipts__viewer-btn" id="rotateBtn" title="Rotate">↻</button>
                    <a href="/<?= htmlspecialchars($file['path']) ?>" download class="fw-receipts__viewer-btn" title="Download">⬇</a>
                </div>
                <div class="fw-receipts__viewer-content" id="viewerContent">
                    <?php if (strpos($file['mime'], 'image') === 0): ?>
                        <img src="/<?= htmlspecialchars($file['path']) ?>" alt="Receipt" id="receiptImage">
                    <?php elseif ($file['mime'] === 'application/pdf'): ?>
                        <iframe src="/<?= htmlspecialchars($file['path']) ?>" style="width:100%; height:100%; border:none;"></iframe>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right: Form -->
            <div class="fw-receipts__form-panel">
                
                <!-- Stepper Interface -->
                <div id="reviewStepper" class="fw-receipts__stepper">
                    <div class="fw-receipts__stepper-nav">
                        <div class="fw-receipts__stepper-step active" data-step="1">1. Vendor</div>
                        <div class="fw-receipts__stepper-step" data-step="2">2. Lines</div>
                        <div class="fw-receipts__stepper-step" data-step="3">3. Policy & Match</div>
                        <div class="fw-receipts__stepper-step" data-step="4">4. Approve</div>
                        <div class="fw-receipts__stepper-step" data-step="5">5. Post</div>
                    </div>
                    <div class="fw-receipts__stepper-content">
                        <!-- Step 1: Vendor -->
                        <section class="fw-step" data-step="1">
                            <h3 class="fw-receipts__form-section-title">Vendor & Invoice Details</h3>
                            <div class="fw-receipts__form-group">
                                <label class="fw-receipts__label">Supplier <span class="fw-receipts__required">*</span></label>
                                <select id="supplierSelect" class="fw-receipts__input" required>
                                    <option value="">— Select Supplier —</option>
                                    <?php foreach ($suppliers as $sup): ?>
                                        <option value="<?= $sup['id'] ?>" <?= ($file['vendor_name'] === $sup['name']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($sup['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="fw-receipts__help-text">
                                    Not found? <a href="/crm/account_new.php?type=supplier" target="_blank" style="color:var(--accent-receipts);">Create new supplier</a>
                                </small>
                            <!-- Compliance status will be inserted here -->
                            <div id="supplierComplianceMsg" style="margin-top:8px;"></div>
                            </div>
                            <div class="fw-receipts__form-group">
                                <label class="fw-receipts__label">Project Workspace <span class="fw-receipts__required">*</span></label>
                                <select id="projectSelect" class="fw-receipts__input" required>
                                    <option value="">— Select Project/Cost Board —</option>
                                    <?php foreach ($costBoards as $cb): ?>
                                        <option value="<?= $cb['board_id'] ?>">
                                            <?= htmlspecialchars($cb['title']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="fw-receipts__form-group">
                                <label class="fw-receipts__label">Invoice Number <span class="fw-receipts__required">*</span></label>
                                <input type="text" id="invoiceNumber" class="fw-receipts__input" value="<?= htmlspecialchars($file['invoice_number'] ?? '') ?>" required>
                            </div>
                            <div class="fw-receipts__form-group">
                                <label class="fw-receipts__label">Invoice Date <span class="fw-receipts__required">*</span></label>
                                <input type="date" id="invoiceDate" class="fw-receipts__input" value="<?= htmlspecialchars($file['invoice_date'] ?? date('Y-m-d')) ?>" required>
                            </div>
                            <div class="fw-receipts__form-row">
                                <div class="fw-receipts__form-group">
                                    <label class="fw-receipts__label">Currency</label>
                                    <select id="currency" class="fw-receipts__input">
                                        <option value="ZAR" <?= ($file['currency'] ?? 'ZAR') === 'ZAR' ? 'selected' : '' ?>>ZAR (R)</option>
                                        <option value="USD" <?= ($file['currency'] ?? '') === 'USD' ? 'selected' : '' ?>>USD ($)</option>
                                        <option value="EUR" <?= ($file['currency'] ?? '') === 'EUR' ? 'selected' : '' ?>>EUR (€)</option>
                                    </select>
                                </div>
                                <div class="fw-receipts__form-group">
                                    <label class="fw-receipts__label">Subtotal</label>
                                    <input type="number" step="0.01" id="subtotal" class="fw-receipts__input" value="<?= number_format($file['subtotal'] ?? 0, 2, '.', '') ?>">
                                </div>
                                <div class="fw-receipts__form-group">
                                    <label class="fw-receipts__label">Tax (VAT)</label>
                                    <input type="number" step="0.01" id="tax" class="fw-receipts__input" value="<?= number_format($file['tax'] ?? 0, 2, '.', '') ?>">
                                </div>
                                <div class="fw-receipts__form-group">
                                    <label class="fw-receipts__label">Total <span class="fw-receipts__required">*</span></label>
                                    <input type="number" step="0.01" id="total" class="fw-receipts__input" value="<?= number_format($file['total'] ?? 0, 2, '.', '') ?>" required>
                                </div>
                            </div>
                            <div class="fw-receipts__step-actions">
                                <button type="button" class="fw-receipts__btn fw-receipts__btn--primary" id="step1Next">Next</button>
                            </div>
                        </section>

                        <!-- Step 2: Lines -->
                        <section class="fw-step" data-step="2" style="display:none;">
                            <h3 class="fw-receipts__form-section-title">Line Items</h3>
                            <div id="lineItemsContainer2">
                                <!-- Lines will be injected via JS -->
                            </div>
                            <button type="button" class="fw-receipts__btn fw-receipts__btn--secondary" id="addLineBtn2">+ Add Line</button>
                            <div class="fw-receipts__step-actions">
                                <button type="button" class="fw-receipts__btn fw-receipts__btn--secondary" id="step2Back">Back</button>
                                <button type="button" class="fw-receipts__btn fw-receipts__btn--primary" id="step2Next">Next</button>
                            </div>
                        </section>

                        <!-- Step 3: Policy & Match -->
                        <section class="fw-step" data-step="3" style="display:none;">
                            <h3 class="fw-receipts__form-section-title">Policy & Match</h3>
                            <div id="policyMatchContent">
                                <?php if (!empty($policyIssues)): ?>
                                    <div class="fw-receipts__alert fw-receipts__alert--<?= $policyIssues[0]['severity'] ?>">
                                        <strong>⚠ Policy Issues:</strong>
                                        <ul style="margin:8px 0 0 0; padding-left:20px;">
                                            <?php foreach ($policyIssues as $issue): ?>
                                                <li><?= htmlspecialchars($issue['message']) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php else: ?>
                                    <p>No policy issues detected.</p>
                                <?php endif; ?>
                                <div id="matchSuggestions">
                                    <!-- Matching suggestions will be inserted via JS -->
                                </div>
                            </div>
                            <div class="fw-receipts__step-actions">
                                <button type="button" class="fw-receipts__btn fw-receipts__btn--secondary" id="step3Back">Back</button>
                                <button type="button" class="fw-receipts__btn fw-receipts__btn--primary" id="step3Next">Next</button>
                            </div>
                        </section>

                        <!-- Step 4: Approve -->
                        <section class="fw-step" data-step="4" style="display:none;">
                            <h3 class="fw-receipts__form-section-title">Approve Bill</h3>
                            <div id="approveSummary">
                                <!-- Summary will be inserted via JS -->
                            </div>
                            <div class="fw-receipts__step-actions">
                                <button type="button" class="fw-receipts__btn fw-receipts__btn--secondary" id="step4Back">Back</button>
                                <button type="button" class="fw-receipts__btn fw-receipts__btn--primary" id="approveBtn">Approve</button>
                            </div>
                        </section>

                        <!-- Step 5: Post -->
                        <section class="fw-step" data-step="5" style="display:none;">
                            <h3 class="fw-receipts__form-section-title">Post to General Ledger</h3>
                            <div id="postSummary">
                                <!-- Posting summary will be inserted via JS -->
                            </div>
                            <div class="fw-receipts__step-actions">
                                <button type="button" class="fw-receipts__btn fw-receipts__btn--secondary" id="step5Back">Back</button>
                                <button type="button" class="fw-receipts__btn fw-receipts__btn--primary" id="postBtn">Post</button>
                                <button type="button" class="fw-receipts__btn fw-receipts__btn--secondary" id="bankRuleBtn">Create Bank Rule</button>
                            </div>
                        </section>
                    </div>
                    <div id="formMessage" style="display:none; margin-top:16px;"></div>
                </div>

            </div>

        </main>

    </div>

    <script>
        // Global variables for review stepper
        const FILE_ID = <?= $fileId ?>;
        // Lines parsed from OCR; will be used as starting point
        const INITIAL_LINES = <?= json_encode($lines) ?>;
        // Existing policy issues (if any) fetched server-side
        const INITIAL_POLICY_ISSUES = <?= json_encode($policyIssues) ?>;
    </script>
    <script src="assets/receipts.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="assets/review.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>