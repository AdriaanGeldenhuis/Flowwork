<?php
// /mail/compose.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-MAIL-1');

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

// Check for reply/forward params
$mode = $_GET['mode'] ?? 'new'; // new | reply | reply_all | forward
$emailId = isset($_GET['email_id']) ? (int)$_GET['email_id'] : null;

$replyData = null;
if ($emailId && in_array($mode, ['reply', 'reply_all', 'forward'])) {
    $stmt = $DB->prepare("
        SELECT e.*, a.email_address, a.account_name
        FROM emails e
        JOIN email_accounts a ON e.account_id = a.account_id
        WHERE e.email_id = ? AND a.company_id = ? AND a.user_id = ?
    ");
    $stmt->execute([$emailId, $companyId, $userId]);
    $replyData = $stmt->fetch();
}

// Fetch accounts for "From" dropdown
$stmt = $DB->prepare("SELECT account_id, account_name, email_address FROM email_accounts WHERE company_id = ? AND user_id = ? AND is_active = 1");
$stmt->execute([$companyId, $userId]);
$accounts = $stmt->fetchAll();

// Fetch templates
$stmt = $DB->prepare("SELECT template_id, name FROM email_templates WHERE company_id = ? ORDER BY name");
$stmt->execute([$companyId]);
$templates = $stmt->fetchAll();

// Fetch signatures (include is_default flag so we can pre-select default)
$stmt = $DB->prepare("SELECT signature_id, name, is_default FROM email_signatures WHERE company_id = ? AND (user_id = ? OR user_id IS NULL) ORDER BY is_default DESC, name");
$stmt->execute([$companyId, $userId]);
$signatures = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compose – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/mail/assets/mail.css?v=<?= ASSET_VERSION ?>">
</head>
<body>
    <main class="fw-mail">
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
                        <div class="fw-mail__app-name">Compose</div>
                    </div>
                </div>

                <div class="fw-mail__greeting">
                    Hello, <span class="fw-mail__greeting-name"><?= htmlspecialchars($firstName) ?></span>
                </div>

                <div class="fw-mail__controls">
                    <a href="/mail/" class="fw-mail__home-btn" title="Back to Mail">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
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
                </div>
            </header>

            <!-- Compose Form -->
            <div class="fw-mail__compose-container">
                <form id="composeForm" class="fw-mail__compose-form">
                    
                    <div class="fw-mail__compose-row">
                        <label class="fw-mail__label">From</label>
                        <select name="account_id" class="fw-mail__input" required>
                            <option value="">Select account...</option>
                            <?php foreach ($accounts as $acc): ?>
                                <option value="<?= $acc['account_id'] ?>"><?= htmlspecialchars($acc['account_name']) ?> &lt;<?= htmlspecialchars($acc['email_address']) ?>&gt;</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="fw-mail__compose-row">
                        <label class="fw-mail__label">To</label>
                        <input type="text" name="to" class="fw-mail__input" placeholder="recipient@example.com" required 
                            value="<?= $replyData && $mode === 'reply' ? htmlspecialchars($replyData['sender']) : '' ?>">
                    </div>

                    <div class="fw-mail__compose-row">
                        <label class="fw-mail__label">Cc</label>
                        <input type="text" name="cc" class="fw-mail__input" placeholder="Optional">
                    </div>

                    <div class="fw-mail__compose-row">
                        <label class="fw-mail__label">Bcc</label>
                        <input type="text" name="bcc" class="fw-mail__input" placeholder="Optional">
                    </div>

                    <div class="fw-mail__compose-row">
                        <label class="fw-mail__label">Subject</label>
                        <input type="text" name="subject" class="fw-mail__input" placeholder="Subject" required
                            value="<?= $replyData && in_array($mode, ['reply','reply_all']) ? 'Re: ' . htmlspecialchars($replyData['subject']) : '' ?>
                            <?= $replyData && $mode === 'forward' ? 'Fwd: ' . htmlspecialchars($replyData['subject']) : '' ?>">
                    </div>

                    <div class="fw-mail__compose-row">
                        <label class="fw-mail__label">Template</label>
                        <select id="templateSelect" class="fw-mail__input">
                            <option value="">None</option>
                            <?php foreach ($templates as $tpl): ?>
                                <option value="<?= $tpl['template_id'] ?>"><?= htmlspecialchars($tpl['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="fw-mail__compose-row">
                        <label class="fw-mail__label">Signature</label>
                        <select name="signature_id" id="signatureSelect" class="fw-mail__input">
                            <option value="">None</option>
                            <?php foreach ($signatures as $sig): ?>
                                <option value="<?= $sig['signature_id'] ?>" <?= $sig['is_default'] ? 'selected' : '' ?>><?= htmlspecialchars($sig['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="fw-mail__compose-row">
                        <label class="fw-mail__label">Message</label>
                        <textarea name="body" id="composeBody" class="fw-mail__textarea" rows="12" required></textarea>
                    </div>

                    <div class="fw-mail__compose-row">
                        <label class="fw-mail__label">Attachments</label>
                        <input type="file" id="attachmentInput" class="fw-mail__input" multiple>
                        <div id="attachmentList" class="fw-mail__attachment-list"></div>
                    </div>

                    <div class="fw-mail__compose-actions">
                        <button type="submit" class="fw-mail__btn fw-mail__btn--primary">Send</button>
                        <button type="button" class="fw-mail__btn fw-mail__btn--secondary" onclick="location.href='/mail/'">Cancel</button>
                    </div>

                    <div id="composeMessage" class="fw-mail__form-message" style="display:none;"></div>

                </form>
            </div>

        </div>
    </main>

    <script src="/mail/assets/mail.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>