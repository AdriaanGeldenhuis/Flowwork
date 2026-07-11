<?php
// /mail/settings.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2026-07-10-mail-crm-parity');

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

// Fetch email accounts + mail preferences. The mail schema (email_accounts/
// user_mail_prefs) is provisioned outside the tracked migrations, so degrade
// gracefully rather than returning a 500 if a table is missing here.
$accounts = [];
$prefs = false;
try {
    $stmt = $DB->prepare("
        SELECT account_id, account_name, email_address, imap_server, smtp_server,
               is_active, last_sync_at, last_error
        FROM email_accounts
        WHERE company_id = ? AND user_id = ?
        ORDER BY account_name
    ");
    $stmt->execute([$companyId, $userId]);
    $accounts = $stmt->fetchAll();

    // Fetch user mail preferences
    $stmt = $DB->prepare("SELECT * FROM user_mail_prefs WHERE company_id = ? AND user_id = ?");
    $stmt->execute([$companyId, $userId]);
    $prefs = $stmt->fetch();
} catch (Throwable $e) {
    error_log('[mail/settings] data load failed: ' . $e->getMessage());
}
if (!$prefs) {
    $prefs = [
        'sla_default_hours' => 24,
        'block_external_images' => 1,
        'notification_enabled' => 1,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <title>Mail Settings – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/mail/assets/mail.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-mail">
    <div class="fw-mail__container">

        <!-- Header -->
        <header class="fw-mail__header">
            <div class="fw-mail__brand">
                <div class="fw-mail__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <polyline points="22,6 12,13 2,6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="fw-mail__brand-text">
                    <div class="fw-mail__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-mail__app-name">Mail – Settings</div>
                </div>
            </div>

            <div class="fw-mail__greeting">
                Hello, <span class="fw-mail__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>

            <div class="fw-mail__controls">
                <a href="/mail/" class="fw-mail__back-btn" title="Back to Mail">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>

                <a href="/" class="fw-mail__home-btn" title="Home">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>

                <button class="fw-mail__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                    <svg class="fw-mail__theme-icon fw-mail__theme-icon--light" viewBox="0 0 24 24" fill="none">
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
                    <svg class="fw-mail__theme-icon fw-mail__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <div class="fw-mail__menu-wrapper">
                    <button class="fw-mail__kebab-toggle" id="kebabToggle" aria-label="Menu">
                        <svg viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                        </svg>
                    </button>
                    <nav class="fw-mail__kebab-menu" id="kebabMenu" aria-hidden="true">
                        <a href="/mail/" class="fw-mail__kebab-item">Inbox</a>
                        <a href="/mail/compose.php" class="fw-mail__kebab-item">Compose</a>
                        <a href="/mail/templates.php" class="fw-mail__kebab-item">Templates</a>
                        <a href="/mail/settings.php" class="fw-mail__kebab-item">Settings</a>
                    </nav>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="fw-mail__main">

            <div class="fw-mail__page-header">
                <h1 class="fw-mail__page-title">Mail Settings</h1>
                <p class="fw-mail__page-subtitle">Manage accounts, signatures, rules, and preferences</p>
            </div>

            <!-- View tabs -->
            <div class="fw-mail__view-tabs">
                <a href="/mail/" class="fw-mail__view-tab">Inbox</a>
                <a href="/mail/templates.php" class="fw-mail__view-tab">Templates</a>
                <a href="/mail/settings.php" class="fw-mail__view-tab fw-mail__view-tab--active">Settings</a>
            </div>

            <!-- Settings -->
            <div class="fw-mail__settings-container">

                <!-- Tabs -->
                <div class="fw-mail__tabs">
                    <button class="fw-mail__tab fw-mail__tab--active" data-tab="accounts">Accounts</button>
                    <button class="fw-mail__tab" data-tab="signatures">Signatures</button>
                    <button class="fw-mail__tab" data-tab="rules">Rules</button>
                    <button class="fw-mail__tab" data-tab="preferences">Preferences</button>
                </div>

                <!-- TAB: Accounts -->
                <div class="fw-mail__tab-panel fw-mail__tab-panel--active" data-panel="accounts">
                    <div class="fw-mail__settings-header">
                        <h2>Email Accounts</h2>
                        <button class="fw-mail__btn fw-mail__btn--primary" onclick="window.MailSettings.showAccountModal(null)">+ Add Account</button>
                    </div>

                    <?php if (empty($accounts)): ?>
                        <div class="fw-mail__empty-state">
                            <p>No email accounts configured yet.</p>
                            <small>Add an IMAP/SMTP account to start sending and receiving email.</small>
                        </div>
                    <?php else: ?>
                        <div class="fw-mail__accounts-list" id="accountList">
                            <?php foreach ($accounts as $acc): ?>
                                <div class="fw-mail__account-card">
                                    <div class="fw-mail__account-card-header">
                                        <h3><?= htmlspecialchars($acc['account_name']) ?></h3>
                                        <span class="fw-mail__badge <?= $acc['is_active'] ? 'fw-mail__badge--active' : 'fw-mail__badge--inactive' ?>">
                                            <?= $acc['is_active'] ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </div>
                                    <div class="fw-mail__account-card-body">
                                        <p><strong>Email:</strong> <?= htmlspecialchars($acc['email_address']) ?></p>
                                        <p><strong>IMAP:</strong> <?= htmlspecialchars($acc['imap_server'] ?: '—') ?></p>
                                        <p><strong>SMTP:</strong> <?= htmlspecialchars($acc['smtp_server'] ?: '—') ?></p>
                                        <?php if ($acc['last_sync_at']): ?>
                                            <p><strong>Last sync:</strong> <?= htmlspecialchars($acc['last_sync_at']) ?></p>
                                        <?php endif; ?>
                                        <?php if ($acc['last_error']): ?>
                                            <p class="fw-mail__error-text"><strong>Error:</strong> <?= htmlspecialchars($acc['last_error']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="fw-mail__card-actions">
                                        <button class="fw-mail__btn fw-mail__btn--small" onclick="window.MailSettings.editAccount(<?= (int)$acc['account_id'] ?>)">Edit</button>
                                        <button class="fw-mail__btn fw-mail__btn--small" onclick="window.MailSettings.testAccount(<?= $acc['account_id'] ?>)">Test</button>
                                        <button class="fw-mail__btn fw-mail__btn--small fw-mail__btn--danger" onclick="window.MailSettings.deleteAccount(<?= $acc['account_id'] ?>)">Delete</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- TAB: Signatures -->
                <div class="fw-mail__tab-panel" data-panel="signatures">
                    <div class="fw-mail__settings-header">
                        <h2>Signatures</h2>
                        <button class="fw-mail__btn fw-mail__btn--primary" onclick="window.MailSettings.showSignatureModal(null)">+ New Signature</button>
                    </div>
                    <div id="signaturesList">
                        <div class="fw-mail__loading">Loading signatures...</div>
                    </div>
                </div>

                <!-- TAB: Rules -->
                <div class="fw-mail__tab-panel" data-panel="rules">
                    <div class="fw-mail__settings-header">
                        <h2>Rules</h2>
                        <button class="fw-mail__btn fw-mail__btn--primary" onclick="window.MailSettings.showRuleModal(null)">+ New Rule</button>
                    </div>
                    <div id="rulesList">
                        <div class="fw-mail__loading">Loading rules...</div>
                    </div>
                </div>

                <!-- TAB: Preferences -->
                <div class="fw-mail__tab-panel" data-panel="preferences">
                    <div class="fw-mail__settings-header">
                        <h2>Preferences</h2>
                    </div>
                    <form id="prefsForm" class="fw-mail__form">
                        <div class="fw-mail__account-card">
                            <div class="fw-mail__form-group">
                                <label class="fw-mail__label">SLA Default Response Time (hours)</label>
                                <input type="number" name="sla_default_hours" class="fw-mail__input"
                                       value="<?= (int)($prefs['sla_default_hours'] ?? 24) ?>" min="1" max="168">
                                <small class="fw-mail__help-text">Target hours to respond to incoming emails</small>
                            </div>

                            <div class="fw-mail__form-group">
                                <label class="fw-mail__checkbox-wrapper">
                                    <input type="checkbox" class="fw-mail__checkbox" name="block_external_images" value="1"
                                           <?= !empty($prefs['block_external_images']) ? 'checked' : '' ?>>
                                    <span class="fw-mail__label">Block External Images</span>
                                </label>
                                <small class="fw-mail__help-text">Prevent loading of remote images in emails for privacy</small>
                            </div>

                            <div class="fw-mail__form-group">
                                <label class="fw-mail__checkbox-wrapper">
                                    <input type="checkbox" class="fw-mail__checkbox" name="notification_enabled" value="1"
                                           <?= !empty($prefs['notification_enabled']) ? 'checked' : '' ?>>
                                    <span class="fw-mail__label">Enable Notifications</span>
                                </label>
                                <small class="fw-mail__help-text">Receive notifications for new incoming emails</small>
                            </div>

                            <div class="fw-mail__form-actions">
                                <button type="submit" class="fw-mail__btn fw-mail__btn--primary">Save Preferences</button>
                            </div>

                            <div id="prefsMessage" class="fw-mail__form-message" style="display:none;"></div>
                        </div>
                    </form>
                </div>

            </div>

        </main>

        <!-- Footer -->
        <footer class="fw-mail__footer">
            <span>Mail v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Dark</span>
        </footer>

    </div>

    <!-- Account Modal -->
    <div class="fw-mail__modal-overlay" id="accountModal" aria-hidden="true">
        <div class="fw-mail__modal">
            <div class="fw-mail__modal-header">
                <h3 class="fw-mail__modal-title" id="accountModalTitle">Add Email Account</h3>
                <button class="fw-mail__modal-close" onclick="window.MailSettings.closeModal('accountModal')">&times;</button>
            </div>
            <div class="fw-mail__modal-body">
                <form id="accountForm" class="fw-mail__form">
                    <input type="hidden" name="account_id" id="accountId" value="">

                    <div class="fw-mail__form-group">
                        <label class="fw-mail__label">Account Name <span class="fw-mail__required">*</span></label>
                        <input type="text" name="account_name" class="fw-mail__input" required placeholder="e.g., Work Email">
                    </div>

                    <div class="fw-mail__form-group">
                        <label class="fw-mail__label">Email Address <span class="fw-mail__required">*</span></label>
                        <input type="email" name="email_address" class="fw-mail__input" required placeholder="you@example.com">
                    </div>

                    <div class="fw-mail__form-row">
                        <div class="fw-mail__form-group">
                            <label class="fw-mail__label">IMAP Server</label>
                            <input type="text" name="imap_server" class="fw-mail__input" placeholder="imap.example.com">
                        </div>
                        <div class="fw-mail__form-group">
                            <label class="fw-mail__label">IMAP Port</label>
                            <input type="number" name="imap_port" class="fw-mail__input" value="993">
                        </div>
                        <div class="fw-mail__form-group">
                            <label class="fw-mail__label">Encryption</label>
                            <select name="imap_encryption" class="fw-mail__input">
                                <option value="ssl" selected>SSL</option>
                                <option value="tls">TLS</option>
                                <option value="none">None</option>
                            </select>
                        </div>
                    </div>

                    <div class="fw-mail__form-row">
                        <div class="fw-mail__form-group">
                            <label class="fw-mail__label">SMTP Server</label>
                            <input type="text" name="smtp_server" class="fw-mail__input" placeholder="smtp.example.com">
                        </div>
                        <div class="fw-mail__form-group">
                            <label class="fw-mail__label">SMTP Port</label>
                            <input type="number" name="smtp_port" class="fw-mail__input" value="587">
                        </div>
                        <div class="fw-mail__form-group">
                            <label class="fw-mail__label">Encryption</label>
                            <select name="smtp_encryption" class="fw-mail__input">
                                <option value="tls" selected>TLS</option>
                                <option value="ssl">SSL</option>
                                <option value="none">None</option>
                            </select>
                        </div>
                    </div>

                    <div class="fw-mail__form-group">
                        <label class="fw-mail__label">Username</label>
                        <input type="text" name="username" class="fw-mail__input" placeholder="Usually your email address">
                    </div>

                    <div class="fw-mail__form-group">
                        <label class="fw-mail__label">Password</label>
                        <input type="password" name="password" class="fw-mail__input" placeholder="Leave blank to keep existing">
                    </div>

                    <div class="fw-mail__form-group">
                        <label class="fw-mail__checkbox-wrapper">
                            <input type="checkbox" class="fw-mail__checkbox" name="is_active" id="accountActive" value="1" checked>
                            <span class="fw-mail__label">Active</span>
                        </label>
                    </div>

                    <div class="fw-mail__form-actions">
                        <button type="submit" class="fw-mail__btn fw-mail__btn--primary">Save Account</button>
                        <button type="button" class="fw-mail__btn fw-mail__btn--secondary" onclick="window.MailSettings.closeModal('accountModal')">Cancel</button>
                    </div>

                    <div id="accountMessage" class="fw-mail__form-message" style="display:none;"></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Signature Modal -->
    <div class="fw-mail__modal-overlay" id="signatureModal" aria-hidden="true">
        <div class="fw-mail__modal">
            <div class="fw-mail__modal-header">
                <h3 class="fw-mail__modal-title" id="signatureModalTitle">New Signature</h3>
                <button class="fw-mail__modal-close" onclick="window.MailSettings.closeModal('signatureModal')">&times;</button>
            </div>
            <div class="fw-mail__modal-body">
                <form id="signatureForm" class="fw-mail__form">
                    <input type="hidden" name="signature_id" id="signatureId" value="">

                    <div class="fw-mail__form-group">
                        <label class="fw-mail__label">Name <span class="fw-mail__required">*</span></label>
                        <input type="text" name="name" class="fw-mail__input" required placeholder="e.g., Default Signature">
                    </div>

                    <div class="fw-mail__form-group">
                        <label class="fw-mail__label">Content (HTML) <span class="fw-mail__required">*</span></label>
                        <textarea name="content_html" class="fw-mail__textarea" rows="8" required placeholder="<p>Regards,<br>Your Name</p>"></textarea>
                    </div>

                    <div class="fw-mail__form-group">
                        <label class="fw-mail__checkbox-wrapper">
                            <input type="checkbox" class="fw-mail__checkbox" name="is_default" value="1">
                            <span class="fw-mail__label">Set as default signature</span>
                        </label>
                    </div>

                    <div class="fw-mail__form-actions">
                        <button type="submit" class="fw-mail__btn fw-mail__btn--primary">Save Signature</button>
                        <button type="button" class="fw-mail__btn fw-mail__btn--secondary" onclick="window.MailSettings.closeModal('signatureModal')">Cancel</button>
                    </div>

                    <div id="signatureMessage" class="fw-mail__form-message" style="display:none;"></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Rule Modal -->
    <div class="fw-mail__modal-overlay" id="ruleModal" aria-hidden="true">
        <div class="fw-mail__modal fw-mail__modal--large">
            <div class="fw-mail__modal-header">
                <h3 class="fw-mail__modal-title" id="ruleModalTitle">New Rule</h3>
                <button class="fw-mail__modal-close" onclick="window.MailSettings.closeModal('ruleModal')">&times;</button>
            </div>
            <div class="fw-mail__modal-body">
                <form id="ruleForm" class="fw-mail__form">
                    <input type="hidden" name="rule_id" id="ruleId" value="">

                    <div class="fw-mail__form-row">
                        <div class="fw-mail__form-group">
                            <label class="fw-mail__label">Rule Name <span class="fw-mail__required">*</span></label>
                            <input type="text" name="name" class="fw-mail__input" required placeholder="e.g., Auto-tag invoices">
                        </div>
                        <div class="fw-mail__form-group">
                            <label class="fw-mail__label">Priority</label>
                            <input type="number" name="priority" class="fw-mail__input" value="100" min="1" max="999">
                        </div>
                    </div>

                    <div class="fw-mail__form-group">
                        <label class="fw-mail__checkbox-wrapper">
                            <input type="checkbox" class="fw-mail__checkbox" name="is_active" value="1" checked>
                            <span class="fw-mail__label">Active</span>
                        </label>
                    </div>

                    <div class="fw-mail__form-group">
                        <label class="fw-mail__label">Conditions</label>
                        <div id="conditionsContainer"></div>
                        <button type="button" class="fw-mail__btn fw-mail__btn--small" onclick="window.MailSettings.addCondition()">+ Add Condition</button>
                    </div>

                    <div class="fw-mail__form-group">
                        <label class="fw-mail__label">Actions</label>
                        <div id="actionsContainer"></div>
                        <button type="button" class="fw-mail__btn fw-mail__btn--small" onclick="window.MailSettings.addAction()">+ Add Action</button>
                    </div>

                    <div class="fw-mail__form-group">
                        <label class="fw-mail__checkbox-wrapper">
                            <input type="checkbox" class="fw-mail__checkbox" name="stop_processing" value="1">
                            <span class="fw-mail__label">Stop processing further rules after this one matches</span>
                        </label>
                    </div>

                    <div class="fw-mail__form-actions">
                        <button type="submit" class="fw-mail__btn fw-mail__btn--primary">Save Rule</button>
                        <button type="button" class="fw-mail__btn fw-mail__btn--secondary" onclick="window.MailSettings.closeModal('ruleModal')">Cancel</button>
                    </div>

                    <div id="ruleMessage" class="fw-mail__form-message" style="display:none;"></div>
                </form>
            </div>
        </div>
    </div>

    <script src="/mail/assets/mail.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
