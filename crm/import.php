<?php
// /crm/import.php - COMPLETE IMPORT/EXPORT PAGE
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-CRM-4');

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

// Fetch import history
$historyStmt = $DB->prepare("
    SELECT * FROM crm_import_history 
    WHERE company_id = ? 
    ORDER BY created_at DESC 
    LIMIT 20
");
$historyStmt->execute([$companyId]);
$importHistory = $historyStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import/Export – <?= htmlspecialchars($companyName) ?></title>
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
                    <div class="fw-crm__app-name">CRM – Import/Export</div>
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
                        <a href="/crm/settings.php" class="fw-crm__kebab-item">CRM Settings</a>
                        <a href="/crm/dedupe.php" class="fw-crm__kebab-item">Dedupe & Merge</a>
                    </nav>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="fw-crm__main">
            
            <div class="fw-crm__page-header">
                <h1 class="fw-crm__page-title">Import & Export Data</h1>
                <p class="fw-crm__page-subtitle">
                    Bulk import or export accounts, contacts, and addresses
                </p>
            </div>

            <!-- Tabs -->
            <div class="fw-crm__view-tabs">
                <button class="fw-crm__view-tab fw-crm__view-tab--active" data-tab="import">
                    📥 Import
                </button>
                <button class="fw-crm__view-tab" data-tab="export">
                    📤 Export
                </button>
                <button class="fw-crm__view-tab" data-tab="history">
                    📜 History
                </button>
            </div>

            <!-- Tab Content -->
            <div class="fw-crm__view-content">
                
                <!-- Import Tab -->
                <div class="fw-crm__tab-panel fw-crm__tab-panel--active" data-panel="import">
                    <div class="fw-crm__info-card">
                        <h3 class="fw-crm__info-card-title">Import CSV Data</h3>
                        <p class="fw-crm__help-text" style="margin-bottom:var(--fw-spacing-lg);">
                            Upload a CSV file to bulk import accounts, contacts, or addresses. 
                            <a href="#" onclick="downloadTemplate(); return false;" style="color:var(--accent-crm);">Download template</a>
                        </p>

                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Import Type</label>
                            <select id="importType" class="fw-crm__input">
                                <option value="accounts">Accounts (Suppliers/Customers)</option>
                                <option value="contacts">Contacts</option>
                                <option value="addresses">Addresses</option>
                            </select>
                        </div>

                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Upload CSV File</label>
                            <div class="fw-crm__file-upload-zone" id="fileUploadZone">
                                <input type="file" id="fileInput" accept=".csv" style="display:none;">
                                <div class="fw-crm__file-upload-icon">📁</div>
                                <div class="fw-crm__file-upload-text">
                                    Click to browse or drag & drop CSV file here
                                </div>
                                <div class="fw-crm__file-upload-hint">
                                    Maximum file size: 10MB
                                </div>
                            </div>
                            <div id="fileInfo" class="fw-crm__file-info" style="display:none; margin-top:var(--fw-spacing-sm);"></div>
                        </div>

                        <div class="fw-crm__form-actions">
                            <button type="button" class="fw-crm__btn fw-crm__btn--primary" id="startImportBtn" disabled>
                                Start Import →
                            </button>
                        </div>
                    </div>

                    <div id="importProgress" style="display:none; margin-top:var(--fw-spacing-lg);">
                        <div class="fw-crm__progress-bar">
                            <div class="fw-crm__progress-fill" id="importProgressFill"></div>
                        </div>
                        <div class="fw-crm__progress-text" id="importProgressText">Processing...</div>
                    </div>

                    <div id="importResults" style="display:none; margin-top:var(--fw-spacing-lg);"></div>
                </div>

                <!-- Export Tab -->
                <div class="fw-crm__tab-panel" data-panel="export">
                    <div class="fw-crm__info-card">
                        <h3 class="fw-crm__info-card-title">Export Data to CSV</h3>
                        <p class="fw-crm__help-text" style="margin-bottom:var(--fw-spacing-lg);">
                            Export your CRM data to a CSV file for backup or analysis.
                        </p>

                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Export Type</label>
                            <select id="exportType" class="fw-crm__input">
                                <option value="accounts">All Accounts</option>
                                <option value="suppliers">Suppliers Only</option>
                                <option value="customers">Customers Only</option>
                                <option value="contacts">All Contacts</option>
                                <option value="addresses">All Addresses</option>
                            </select>
                        </div>

                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Format</label>
                            <select id="exportFormat" class="fw-crm__input">
                                <option value="csv">CSV (Comma-separated)</option>
                                <option value="xlsx" disabled>Excel (Coming soon)</option>
                            </select>
                        </div>

                        <div class="fw-crm__form-group">
                            <label class="fw-crm__checkbox-wrapper">
                                <input type="checkbox" id="includeInactive" class="fw-crm__checkbox">
                                <span>Include Inactive Accounts</span>
                            </label>
                        </div>

                        <div class="fw-crm__form-actions">
                            <button type="button" class="fw-crm__btn fw-crm__btn--primary" onclick="startExport()">
                                📥 Download Export
                            </button>
                        </div>
                    </div>
                </div>

                <!-- History Tab -->
                <div class="fw-crm__tab-panel" data-panel="history">
                    <?php if (count($importHistory) > 0): ?>
                        <div class="fw-crm__history-list">
                            <?php foreach ($importHistory as $history): ?>
                            <div class="fw-crm__history-card">
                                <div class="fw-crm__history-header">
                                    <div>
                                        <strong><?= htmlspecialchars($history['filename']) ?></strong><br>
                                        <small><?= ucfirst($history['type']) ?> • <?= date('d M Y H:i', strtotime($history['created_at'])) ?></small>
                                    </div>
                                    <span class="fw-crm__badge fw-crm__badge--<?= $history['status'] ?>">
                                        <?= ucfirst($history['status']) ?>
                                    </span>
                                </div>
                                <div class="fw-crm__history-stats">
                                    <div class="fw-crm__history-stat">
                                        <span class="fw-crm__history-stat-value"><?= $history['total_rows'] ?></span>
                                        <span class="fw-crm__history-stat-label">Total</span>
                                    </div>
                                    <div class="fw-crm__history-stat fw-crm__history-stat--success">
                                        <span class="fw-crm__history-stat-value"><?= $history['successful_rows'] ?></span>
                                        <span class="fw-crm__history-stat-label">Success</span>
                                    </div>
                                    <div class="fw-crm__history-stat fw-crm__history-stat--error">
                                        <span class="fw-crm__history-stat-value"><?= $history['failed_rows'] ?></span>
                                        <span class="fw-crm__history-stat-label">Failed</span>
                                    </div>
                                </div>
                                <?php if ($history['error_log']): ?>
                                <details style="margin-top:var(--fw-spacing-sm);">
                                    <summary style="cursor:pointer; font-size:13px; color:var(--accent-danger);">
                                        View Errors
                                    </summary>
                                    <pre style="margin-top:var(--fw-spacing-sm); padding:var(--fw-spacing-sm); background:var(--fw-bg-base); border-radius:var(--fw-radius-sm); overflow:auto; max-height:200px; font-size:12px;"><?= htmlspecialchars($history['error_log']) ?></pre>
                                </details>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="fw-crm__empty-state">
                            No import history yet
                        </div>
                    <?php endif; ?>
                </div>

            </div>

        </main>

        <!-- Footer -->
        <footer class="fw-crm__footer">
            <span>CRM v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>

    </div>

    <script src="/crm/assets/crm.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
    // Placeholder functions
    function downloadTemplate() {
        const type = document.getElementById('importType').value;
        window.location.href = '/crm/ajax/import_template.php?type=' + type;
    }

    function startExport() {
        const type = document.getElementById('exportType').value;
        const format = document.getElementById('exportFormat').value;
        const includeInactive = document.getElementById('includeInactive').checked ? '1' : '0';
        
        window.location.href = `/crm/ajax/export.php?type=${type}&format=${format}&include_inactive=${includeInactive}`;
    }

    // Tab switching
    const tabs = document.querySelectorAll('.fw-crm__view-tab');
    const panels = document.querySelectorAll('.fw-crm__tab-panel');

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.tab;
            
            tabs.forEach(t => t.classList.remove('fw-crm__view-tab--active'));
            panels.forEach(p => p.classList.remove('fw-crm__tab-panel--active'));
            
            tab.classList.add('fw-crm__view-tab--active');
            document.querySelector(`[data-panel="${target}"]`).classList.add('fw-crm__tab-panel--active');
        });
    });
    </script>
</body>
</html>