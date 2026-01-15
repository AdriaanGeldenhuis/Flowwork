<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-RECEIPTS-1');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

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

// Fetch settings
$stmt = $DB->prepare("SELECT * FROM receipt_settings WHERE company_id = ?");
$stmt->execute([$companyId]);
$settings = $stmt->fetch() ?: [
    'max_file_size_mb' => 15,
    'auto_split_lines' => 1
];
$maxSizeMB = $settings['max_file_size_mb'];
$maxSizeBytes = $maxSizeMB * 1024 * 1024;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Receipt – <?= htmlspecialchars($companyName) ?></title>
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
                    <div class="fw-receipts__app-name">Upload Receipt</div>
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
                
                <button class="fw-receipts__theme-toggle" id="themeToggle" aria-label="Toggle theme">
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
                <h1 class="fw-receipts__page-title">Upload Receipts</h1>
                <p class="fw-receipts__page-subtitle">Drag & drop files, or capture with your camera</p>
            </div>

            <!-- Upload Zone -->
            <div class="fw-receipts__upload-zone" id="uploadZone">
                <input type="file" id="fileInput" accept=".pdf,.jpg,.jpeg,.png,.webp" multiple hidden>
                <!-- Bulk import input (ZIP) -->
                <input type="file" id="bulkFileInput" accept=".zip" hidden>
                
                <div class="fw-receipts__upload-icon">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5m0 0L7 8m5-5v12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <h3 class="fw-receipts__upload-title">Drop files here or click to browse</h3>
                <p class="fw-receipts__upload-hint">Supported: PDF, JPG, PNG, WEBP (max <?= $maxSizeMB ?>MB per file)</p>
                
                <div class="fw-receipts__upload-actions">
                    <button type="button" class="fw-receipts__btn fw-receipts__btn--primary" id="browseBtn">
                        Browse Files
                    </button>
                    <button type="button" class="fw-receipts__btn fw-receipts__btn--secondary" id="cameraBtn">
                        📷 Capture Photo
                    </button>
                    <!-- Bulk import button -->
                    <button type="button" class="fw-receipts__btn fw-receipts__btn--secondary" id="bulkBtn">
                        📂 Bulk Import (ZIP)
                    </button>
                </div>
            </div>

            <!-- Upload Progress -->
            <div class="fw-receipts__upload-list" id="uploadList" style="display:none;">
                <h3 class="fw-receipts__section-title">Uploading Files</h3>
                <div id="uploadItems"></div>
            </div>

            <!-- Bulk Import Progress -->
            <div class="fw-receipts__upload-list" id="bulkList" style="display:none;">
                <h3 class="fw-receipts__section-title">Bulk Import</h3>
                <div id="bulkItems"></div>
            </div>

        </main>

        <!-- Footer -->
        <footer class="fw-receipts__footer">
            <span>Receipts v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>

    </div>

    <!-- Camera Modal -->
    <div class="fw-receipts__modal-overlay" id="cameraModal" aria-hidden="true">
        <div class="fw-receipts__modal fw-receipts__modal--large">
            <div class="fw-receipts__modal-header">
                <h2 class="fw-receipts__modal-title">Capture Receipt</h2>
                <button class="fw-receipts__modal-close" id="closeCameraBtn">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            <div class="fw-receipts__modal-body">
                <video id="cameraStream" autoplay playsinline style="width:100%; border-radius:12px; background:#000;"></video>
                <canvas id="cameraCanvas" style="display:none;"></canvas>
            </div>
            <div class="fw-receipts__modal-footer">
                <button class="fw-receipts__btn fw-receipts__btn--secondary" id="cancelCameraBtn">Cancel</button>
                <button class="fw-receipts__btn fw-receipts__btn--primary" id="captureBtn">📸 Capture</button>
            </div>
        </div>
    </div>

    <script>
        const MAX_SIZE_BYTES = <?= $maxSizeBytes ?>;
        const ALLOWED_TYPES = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    </script>
    <script src="assets/receipts.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="assets/upload.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>