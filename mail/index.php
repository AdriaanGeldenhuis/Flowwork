<?php
// /mail/index.php
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

// Fetch accounts count
$stmt = $DB->prepare("SELECT COUNT(*) FROM email_accounts WHERE company_id = ? AND user_id = ? AND is_active = 1");
$stmt->execute([$companyId, $userId]);
$accountCount = (int)$stmt->fetchColumn();

// Fetch unread count
$stmt = $DB->prepare("
  SELECT COUNT(*) FROM emails e
  JOIN email_accounts a ON e.account_id = a.account_id
  WHERE a.company_id = ? AND a.user_id = ? AND e.is_read = 0 AND e.direction = 'incoming'
");
$stmt->execute([$companyId, $userId]);
$unreadCount = (int)$stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mail – <?= htmlspecialchars($companyName) ?></title>
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
                        <div class="fw-mail__app-name">Mail</div>
                    </div>
                </div>

                <div class="fw-mail__greeting">
                    Hello, <span class="fw-mail__greeting-name"><?= htmlspecialchars($firstName) ?></span>
                </div>

                <div class="fw-mail__controls">
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
                            <a href="/mail/compose.php" class="fw-mail__kebab-item">Compose</a>
                            <a href="/mail/settings.php" class="fw-mail__kebab-item">Settings</a>
                            <a href="/mail/templates.php" class="fw-mail__kebab-item">Templates</a>
                            <button class="fw-mail__kebab-item" onclick="MailApp.syncAll()">Sync All</button>
                        </nav>
                    </div>
                </div>
            </header>

            <!-- 3-pane layout -->
            <div class="fw-mail__layout">
                
                <!-- Sidebar: Folders -->
                <aside class="fw-mail__sidebar" id="mailSidebar">
                    <?php if ($accountCount === 0): ?>
                        <div class="fw-mail__no-accounts">
                            <p>No email accounts configured.</p>
                            <a href="/mail/settings.php" class="fw-mail__btn fw-mail__btn--primary">Add Account</a>
                        </div>
                    <?php else: ?>
                        <button class="fw-mail__compose-btn" onclick="location.href='/mail/compose.php'">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:20px;height:20px;margin-right:8px;">
                                <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            Compose
                        </button>
                        
                        <div class="fw-mail__folder-list" id="folderList">
                            <div class="fw-mail__loading">Loading folders...</div>
                        </div>
                    <?php endif; ?>
                </aside>

                <!-- Middle: Thread list -->
                <section class="fw-mail__thread-list" id="threadList">
                    <div class="fw-mail__thread-header">
                        <h2 id="threadListTitle">Inbox</h2>
                        <div class="fw-mail__thread-controls">
                            <input type="search" class="fw-mail__search" id="threadSearch" placeholder="Search..." autocomplete="off">
                            <select class="fw-mail__filter" id="filterRead">
                                <option value="">All</option>
                                <option value="unread">Unread</option>
                                <option value="read">Read</option>
                            </select>
                        </div>
                    </div>
                    <div class="fw-mail__threads" id="threads">
                        <div class="fw-mail__loading">Select a folder</div>
                    </div>
                </section>

                <!-- Right: Message preview -->
                <section class="fw-mail__preview" id="messagePreview">
                    <div class="fw-mail__preview-empty">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:64px;height:64px;opacity:0.3;">
                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" stroke="currentColor" stroke-width="2"/>
                            <polyline points="22,6 12,13 2,6" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        <p>Select a message to view</p>
                    </div>
                </section>

            </div>

        </div>
    </main>

    <script src="/mail/assets/mail.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>