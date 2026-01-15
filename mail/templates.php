<?php
// /mail/templates.php
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

// Fetch templates
$stmt = $DB->prepare("SELECT * FROM email_templates WHERE company_id = ? ORDER BY category, name");
$stmt->execute([$companyId]);
$templates = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Templates – <?= htmlspecialchars($companyName) ?></title>
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
                        <div class="fw-mail__app-name">Email Templates</div>
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

            <!-- Templates Content -->
            <div class="fw-mail__templates-container">
                <div class="fw-mail__settings-header">
                    <h2>Email Templates</h2>
                    <button class="fw-mail__btn fw-mail__btn--primary" onclick="MailTemplates.showModal()">+ New Template</button>
                </div>

                <?php if (empty($templates)): ?>
                    <div class="fw-mail__empty-state">
                        <p>No templates yet.</p>
                        <small>Create reusable email templates with placeholders like {first_name}, {company_name}, {quote_number}.</small>
                    </div>
                <?php else: ?>
                    <div class="fw-mail__templates-grid">
                        <?php foreach ($templates as $tpl): ?>
                            <div class="fw-mail__template-card">
                                <div class="fw-mail__template-card-header">
                                    <h3><?= htmlspecialchars($tpl['name']) ?></h3>
                                    <?php if ($tpl['category']): ?>
                                        <span class="fw-mail__badge"><?= htmlspecialchars($tpl['category']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="fw-mail__template-card-body">
                                    <p><strong>Subject:</strong> <?= htmlspecialchars($tpl['subject'] ?: '(No subject)') ?></p>
                                    <div class="fw-mail__template-preview"><?= htmlspecialchars(substr(strip_tags($tpl['body_html']), 0, 150)) ?>...</div>
                                </div>
                                <div class="fw-mail__card-actions">
                                    <button class="fw-mail__btn fw-mail__btn--small" onclick="MailTemplates.edit(<?= $tpl['template_id'] ?>)">Edit</button>
                                    <button class="fw-mail__btn fw-mail__btn--small" onclick="MailTemplates.use(<?= $tpl['template_id'] ?>)">Use</button>
                                    <button class="fw-mail__btn fw-mail__btn--small fw-mail__btn--danger" onclick="MailTemplates.delete(<?= $tpl['template_id'] ?>)">Delete</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </main>

    <!-- Template Modal -->
    <div class="fw-mail__modal-overlay" id="templateModal">
        <div class="fw-mail__modal fw-mail__modal--large">
            <div class="fw-mail__modal-header">
                <h3 class="fw-mail__modal-title" id="templateModalTitle">New Template</h3>
                <button class="fw-mail__modal-close" onclick="MailTemplates.closeModal()">&times;</button>
            </div>
            <div class="fw-mail__modal-body">
                <form id="templateForm" class="fw-mail__form">
                    <input type="hidden" name="template_id" id="templateId">
                    
                    <div class="fw-mail__form-row">
                        <div class="fw-mail__form-group">
                            <label class="fw-mail__label">Template Name <span class="fw-mail__required">*</span></label>
                            <input type="text" name="name" class="fw-mail__input" required>
                        </div>
                        <div class="fw-mail__form-group">
                            <label class="fw-mail__label">Category</label>
                            <input type="text" name="category" class="fw-mail__input" placeholder="e.g., RFQ, Follow-up">
                        </div>
                    </div>

                    <div class="fw-mail__form-group">
                        <label class="fw-mail__label">Subject</label>
                        <input type="text" name="subject" class="fw-mail__input" placeholder="Use {placeholders}">
                    </div>

                    <div class="fw-mail__form-group">
                        <label class="fw-mail__label">Body <span class="fw-mail__required">*</span></label>
                        <textarea name="body_html" class="fw-mail__textarea" rows="12" required></textarea>
                        <small class="fw-mail__help-text">
                            Available placeholders: {first_name}, {last_name}, {company_name}, {email}, {quote_number}, {invoice_number}, {total}, {due_date}, {project_name}, {board_name}, {item_title}
                        </small>
                    </div>

                    <div class="fw-mail__form-actions">
                        <button type="submit" class="fw-mail__btn fw-mail__btn--primary">Save Template</button>
                        <button type="button" class="fw-mail__btn fw-mail__btn--secondary" onclick="MailTemplates.closeModal()">Cancel</button>
                    </div>

                    <div id="templateMessage" class="fw-mail__form-message" style="display:none;"></div>
                </form>
            </div>
        </div>
    </div>

    <script src="/mail/assets/mail.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>