<?php
// /qi/quote_view.php - COMPLETE WITH CONVERSION
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/Branding.php';
require_once __DIR__ . '/lib/Currencies.php';

define('ASSET_VERSION', QI_ASSET_VERSION);

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];
$quoteId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$quoteId) {
    header('Location: /qi/');
    exit;
}

try {
    $stmt = $DB->prepare("
        SELECT q.*, 
               u.first_name AS creator_first_name, 
               u.last_name AS creator_last_name,
               c.name AS company_name, 
               c.logo_url, 
               c.vat_number, 
               c.tax_number,
               c.reg_number,
               c.website,
               c.phone AS company_phone, 
               c.email AS company_email,
               c.address_line1 AS company_address1, 
               c.address_line2 AS company_address2,
               c.city AS company_city, 
               c.region AS company_region,
               c.postal AS company_postal,
               c.bank_name, 
               c.bank_account_number, 
               c.bank_branch_code,
               c.quote_footer_text, 
               c.primary_color,
               c.secondary_color,
               c.qi_font_family,
               c.qi_show_company_address,
               c.qi_show_company_phone,
               c.qi_show_company_email,
               c.qi_show_company_website,
               c.qi_show_vat_number,
               c.qi_show_tax_number,
               c.qi_show_reg_number,
               c.qi_show_payment_details,
               c.qi_heading_color,
               c.qi_text_color,
               c.qi_table_header_text,
               c.qi_bg_color,
               c.qi_border_radius,
               c.qi_logo_size,
               c.qi_logo_position,
               c.qi_template,
               c.qi_custom_css,
               c.qi_quote_title,
               ca.name AS customer_name,
               ca.email AS customer_email,
               ca.phone AS customer_phone,
               ca.vat_no AS customer_vat,
               ca.reg_no AS customer_reg,
               addr.line1 AS customer_address1,
               addr.line2 AS customer_address2,
               addr.city AS customer_city,
               addr.region AS customer_region,
               addr.postal_code AS customer_postal,
               p.name AS project_name
        FROM quotes q
        LEFT JOIN users u ON q.created_by = u.id
        LEFT JOIN companies c ON q.company_id = c.id
        LEFT JOIN crm_accounts ca ON q.customer_id = ca.id
        LEFT JOIN crm_addresses addr ON addr.account_id = ca.id AND addr.id = (SELECT a2.id FROM crm_addresses a2 WHERE a2.account_id = ca.id ORDER BY FIELD(a2.type, 'billing', 'head_office', 'shipping', 'site') LIMIT 1)
        LEFT JOIN projects p ON q.project_id = p.project_id
        WHERE q.id = ? AND q.company_id = ?
    ");
    $stmt->execute([$quoteId, $companyId]);
    $quote = $stmt->fetch();

    if (!$quote) {
        header('Location: /qi/');
        exit;
    }

    $stmt = $DB->prepare("SELECT * FROM quote_lines WHERE quote_id = ? ORDER BY sort_order");
    $stmt->execute([$quoteId]);
    $lines = $stmt->fetchAll();

    // Fetch payment milestones
    $milestones = [];
    if (!empty($quote['has_milestones'])) {
        $stmt = $DB->prepare("SELECT * FROM payment_milestones WHERE entity_type = 'quote' AND entity_id = ? AND company_id = ? ORDER BY sort_order");
        $stmt->execute([$quoteId, $companyId]);
        $milestones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $firstName = $user['first_name'] ?? 'User';

} catch (Exception $e) {
    error_log('Quote view error: ' . $e->getMessage());
    header('Location: /qi/?error=1');
    exit;
}

$canEdit = in_array($quote['status'], ['draft']);
$canSend = in_array($quote['status'], ['draft', 'sent']);
$canConvert = $quote['status'] === 'accepted';

// Colour, text and font customisation — resolved centrally (qi/lib/Branding.php).
$brand = Branding::resolve($quote, 'quote');
$docTitle     = htmlspecialchars($brand['title']);
$template     = $brand['template'];
$logoPosition = $brand['logo_position'];
$customCss    = Branding::customCss($brand);

$showAddress = $brand['show']['address'];
$showPhone   = $brand['show']['phone'];
$showEmail   = $brand['show']['email'];
$showWebsite = $brand['show']['website'];
$showVat     = $brand['show']['vat'];
$showTax     = $brand['show']['tax'];
$showReg     = $brand['show']['reg'];
$showPayment = $brand['show']['payment'];

// Document currency formatting
$docCurrency = Currencies::isValid($quote['currency'] ?? null) ? strtoupper($quote['currency']) : Currencies::BASE;
$docSymbol   = Currencies::symbol($docCurrency);
$docRate     = (float)($quote['exchange_rate'] ?? 1) ?: 1.0;
$isForeign   = ($docCurrency !== Currencies::BASE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <title><?= htmlspecialchars($quote['quote_number']) ?> – <?= htmlspecialchars($quote['company_name']) ?></title>
    
    <?= Branding::fontHeadLinks() ?>


    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/qi/assets/qi.css?v=<?= ASSET_VERSION ?>">
    <link rel="stylesheet" href="/qi/assets/templates-pro.css?v=<?= ASSET_VERSION ?>">

    <style>
<?= Branding::documentStyle($brand) ?>

        /* The quote document always renders the desktop layout at a fixed
           940px wide. On phones it sits inside .fw-qi__doc-viewport which
           CSS-zooms it down to fit the screen and lets the user pinch to
           zoom in/out (handled by the pinch script at the bottom of this
           page). The app shell (header, footer) keeps the normal
           device-width viewport, so it stays usable. */
        .fw-qi__doc-viewport {
            width: 100%;
            overflow: auto;
            -webkit-overflow-scrolling: touch;
            touch-action: pan-x pan-y pinch-zoom;
            background: #e5e7eb;
        }
        .fw-qi[data-theme="dark"] .fw-qi__doc-viewport { background: #111827; }
        .fw-qi__doc-viewport .fw-qi__document {
            width: 940px;
            max-width: none;
            margin: 0 auto;
            zoom: var(--qi-zoom, 1);
        }
        <?= $customCss ?>
    </style>
</head>
<body class="fw-qi">
    <div class="fw-qi__container">
        
        <header class="fw-qi__header">
            <div class="fw-qi__brand">
                <div class="fw-qi__logo-tile">
                    <?php if ($quote['logo_url']): ?>
                        <img src="<?= htmlspecialchars($quote['logo_url']) ?>" alt="Logo" style="width:100%;height:100%;object-fit:contain;">
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2"/><polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="2"/></svg>
                    <?php endif; ?>
                </div>
                <div class="fw-qi__brand-text">
                    <div class="fw-qi__company-name"><?= htmlspecialchars($quote['company_name']) ?></div>
                    <div class="fw-qi__app-name"><?= htmlspecialchars($quote['quote_number']) ?></div>
                </div>
            </div>
            <div class="fw-qi__greeting">Hello, <span class="fw-qi__greeting-name"><?= htmlspecialchars($firstName) ?></span></div>
            <div class="fw-qi__controls">
                <a href="/qi/" class="fw-qi__home-btn" title="Back"><svg viewBox="0 0 24 24" fill="none"><path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></a>
                <button class="fw-qi__theme-toggle" id="themeToggle"><svg class="fw-qi__theme-icon fw-qi__theme-icon--light" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/></svg><svg class="fw-qi__theme-icon fw-qi__theme-icon--dark" viewBox="0 0 24 24" fill="none"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2"/></svg></button>
                <div class="fw-qi__menu-wrapper">
    <button class="fw-qi__kebab-toggle" id="kebabToggle">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:20px;height:20px;">
            <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
            <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
            <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
        </svg>
    </button>
    <nav class="fw-qi__kebab-menu" id="kebabMenu">
        
        <?php if ($quote['status'] === 'draft'): ?>
            <a href="/qi/quote_edit.php?id=<?= $quoteId ?>" class="fw-qi__kebab-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
                Edit Quote
            </a>
        <?php endif; ?>
        
        <?php if (in_array($quote['status'], ['draft', 'sent'])): ?>
            <button onclick="QuoteView.sendQuote()" class="fw-qi__kebab-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                    <line x1="22" y1="2" x2="11" y2="13"/>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                </svg>
                Send to Customer
            </button>
        <?php endif; ?>
        
        <?php if ($quote['status'] === 'accepted'): ?>
            <button onclick="QuoteView.convertToInvoice()" class="fw-qi__kebab-item" style="background:linear-gradient(135deg,rgba(16,185,129,0.15),rgba(5,150,105,0.1));color:#10b981;font-weight:700;border-left:3px solid #10b981;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                    <path d="M9 11l3 3L22 4"/>
                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                </svg>
                Convert to Invoice ✨
            </button>
        <?php endif; ?>
        
        <hr style="margin:8px 0;border:none;border-top:1px solid var(--fw-border);">

        <button onclick="QuoteView.printQuote()" class="fw-qi__kebab-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                <polyline points="6 9 6 2 18 2 18 9"/>
                <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                <rect x="6" y="14" width="12" height="8"/>
            </svg>
            Print
        </button>
        
        <button onclick="QuoteView.downloadPDF()" class="fw-qi__kebab-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            Download PDF
        </button>
        
        <button onclick="QuoteView.duplicateQuote()" class="fw-qi__kebab-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                <rect x="9" y="9" width="13" height="13" rx="2"/>
                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
            </svg>
            Duplicate Quote
        </button>
        <button onclick="QuoteView.copyPublicLink()" class="fw-qi__kebab-item">
            <!-- Link / copy icon -->
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                <path d="M10 13a5 5 0 0 1 7.07 0l1.41 1.41a5 5 0 1 1-7.07 7.07l-1.41-1.41" />
                <path d="M14 11a5 5 0 0 0-7.07 0l-1.41 1.41a5 5 0 1 0 7.07 7.07l1.41-1.41" />
            </svg>
            Copy Public Link
        </button>

        <?php // Admin accept/decline actions: show when quote not yet accepted or declined ?>
        <?php if (!in_array($quote['status'], ['accepted', 'declined'])): ?>
            <button onclick="QuoteView.acceptQuote()" class="fw-qi__kebab-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                    <polyline points="20 6 9 17 4 12" />
                </svg>
                Accept Quote
            </button>
            <button onclick="QuoteView.declineQuote()" class="fw-qi__kebab-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                    <line x1="18" y1="6" x2="6" y2="18" />
                    <line x1="6" y1="6" x2="18" y2="18" />
                </svg>
                Decline Quote
            </button>
        <?php endif; ?>
        
        <?php if ($quote['status'] !== 'converted'): ?>
            <hr style="margin:8px 0;border:none;border-top:1px solid var(--fw-border);">
            <button onclick="QuoteView.deleteQuote()" class="fw-qi__kebab-item" style="color:#ef4444;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                </svg>
                Delete Quote
            </button>
        <?php endif; ?>
        
    </nav>
</div>
        </header>

        <main class="fw-qi__main">
            <div class="fw-qi__doc-viewport" id="docViewport">
            <div class="fw-qi__document" data-template="<?= $template ?>">
                
                <div class="fw-qi__doc-header">
                    <div class="fw-qi__doc-header-left<?= $logoPosition !== 'left' ? ' fw-qi__doc-header-left--' . $logoPosition : '' ?>">
                        <?php if ($quote['logo_url']): ?>
                            <img src="<?= htmlspecialchars($quote['logo_url']) ?>" alt="Logo" class="fw-qi__doc-logo">
                        <?php endif; ?>
                        <div class="fw-qi__doc-company">
                            <h1><?= htmlspecialchars($quote['company_name']) ?></h1>
                            <?php if ($showAddress): ?>
                                <?php if ($quote['company_address1']): ?><p><?= htmlspecialchars($quote['company_address1']) ?></p><?php endif; ?>
                                <?php if ($quote['company_address2']): ?><p><?= htmlspecialchars($quote['company_address2']) ?></p><?php endif; ?>
                                <?php if ($quote['company_city']): ?><p><?= htmlspecialchars($quote['company_city']) ?>, <?= htmlspecialchars($quote['company_postal']) ?></p><?php endif; ?>
                            <?php endif; ?>
                            <?php if ($showReg && $quote['reg_number']): ?><p><strong>Reg No:</strong> <?= htmlspecialchars($quote['reg_number']) ?></p><?php endif; ?>
                            <?php if ($showTax && $quote['tax_number']): ?><p><strong>Tax:</strong> <?= htmlspecialchars($quote['tax_number']) ?></p><?php endif; ?>
                            <?php if ($showVat && $quote['vat_number']): ?><p><strong>VAT No:</strong> <?= htmlspecialchars($quote['vat_number']) ?></p><?php endif; ?>
                            <?php if ($showPhone && $quote['company_phone']): ?><p><?= htmlspecialchars($quote['company_phone']) ?></p><?php endif; ?>
                            <?php if ($showEmail && $quote['company_email']): ?><p><?= htmlspecialchars($quote['company_email']) ?></p><?php endif; ?>
                            <?php if ($showWebsite && $quote['website']): ?><p><?= htmlspecialchars($quote['website']) ?></p><?php endif; ?>
                        </div>
                    </div>
                    <div class="fw-qi__doc-header-right">
                        <h2 class="fw-qi__doc-title"><?= $docTitle ?></h2>
                        <div class="fw-qi__doc-number"><?= htmlspecialchars($quote['quote_number']) ?></div>
                        <table class="fw-qi__doc-info-table" style="margin-top:8px;">
                            <tr><td>Quote Date:</td><td><strong><?= date('d M Y', strtotime($quote['issue_date'])) ?></strong></td></tr>
                            <tr><td>Valid Until:</td><td><strong><?= date('d M Y', strtotime($quote['expiry_date'])) ?></strong></td></tr>
                            <?php if ($isForeign): ?>
                                <tr><td>Currency:</td><td><strong><?= htmlspecialchars($docCurrency) ?> (<?= htmlspecialchars(Currencies::name($docCurrency)) ?>)</strong></td></tr>
                            <?php endif; ?>
                        </table>
                        <span class="fw-qi__badge fw-qi__badge--<?= $quote['status'] ?>"><?= strtoupper($quote['status']) ?></span>

                        <div class="fw-qi__doc-bill-to" style="margin-top:18px;padding-top:14px;border-top:1px solid rgba(0,0,0,0.08);">
                            <h3 style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--qi-heading-color);margin:0 0 8px 0;">Bill To</h3>
                            <p style="margin:4px 0;"><strong><?= htmlspecialchars($quote['customer_name'] ?? 'No Customer') ?></strong></p>
                            <?php if (!empty($quote['customer_address1'])): ?><p style="margin:3px 0;"><?= htmlspecialchars($quote['customer_address1']) ?></p><?php endif; ?>
                            <?php if (!empty($quote['customer_address2'])): ?><p style="margin:3px 0;"><?= htmlspecialchars($quote['customer_address2']) ?></p><?php endif; ?>
                            <?php $custCity = implode(', ', array_filter([trim($quote['customer_city'] ?? ''), trim(($quote['customer_region'] ?? '') . ' ' . ($quote['customer_postal'] ?? ''))], fn($p) => $p !== '')); ?>
                            <?php if ($custCity !== ''): ?><p style="margin:3px 0;"><?= htmlspecialchars($custCity) ?></p><?php endif; ?>
                            <?php if ($quote['customer_phone']): ?><p style="margin:3px 0;">Tel: <?= htmlspecialchars($quote['customer_phone']) ?></p><?php endif; ?>
                            <?php if ($quote['customer_email']): ?><p style="margin:3px 0;">Email: <?= htmlspecialchars($quote['customer_email']) ?></p><?php endif; ?>
                            <?php if (!empty($quote['customer_vat'])): ?><p style="margin:3px 0;">VAT No: <?= htmlspecialchars($quote['customer_vat']) ?></p><?php endif; ?>
                            <?php if (!empty($quote['customer_reg'])): ?><p style="margin:3px 0;">Reg No: <?= htmlspecialchars($quote['customer_reg']) ?></p><?php endif; ?>
                        </div>
                                            </div>
                </div>

                <?php if ($quote['project_name']): ?>
                <div class="fw-qi__classic-project">
                    <h2><?= htmlspecialchars($quote['project_name']) ?></h2>
                </div>
                <?php endif; ?>

                <div class="fw-qi__doc-section">
                    <h3>Items</h3>
                    <table class="fw-qi__doc-table">
                        <thead><tr><th style="width:50px;">#</th><th>Description</th><th class="fw-qi__doc-table-center" style="width:100px;">Qty</th><th class="fw-qi__doc-table-right" style="width:130px;">Unit Price</th><th class="fw-qi__doc-table-right" style="width:130px;">Total</th></tr></thead>
                        <tbody>
                            <?php if (count($lines) > 0): ?>
                                <?php foreach ($lines as $idx => $line): ?>
                                    <tr>
                                        <td><?= $idx + 1 ?></td>
                                        <td><?= htmlspecialchars($line['item_description']) ?></td>
                                        <td class="fw-qi__doc-table-center"><?= number_format($line['quantity'], 2) ?></td>
                                        <td class="fw-qi__doc-table-right"><?= htmlspecialchars($docSymbol) ?> <?= number_format($line['unit_price'], 2) ?></td>
                                        <td class="fw-qi__doc-table-right"><strong><?= htmlspecialchars($docSymbol) ?> <?= number_format($line['line_total'], 2) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align:center;padding:40px;color:#999;">No items</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div class="fw-qi__doc-totals">
                        <div class="fw-qi__doc-total-row"><span>Subtotal:</span><span><?= htmlspecialchars($docSymbol) ?> <?= number_format($quote['subtotal'], 2) ?></span></div>
                        <div class="fw-qi__doc-total-row"><span>Discount:</span><span><?= htmlspecialchars($docSymbol) ?> <?= number_format($quote['discount'], 2) ?></span></div>
                        <div class="fw-qi__doc-total-row"><span>VAT:</span><span><?= htmlspecialchars($docSymbol) ?> <?= number_format($quote['tax'], 2) ?></span></div>
                        <div class="fw-qi__doc-total-row fw-qi__doc-total-row--grand"><span>TOTAL:</span><span><?= htmlspecialchars($docSymbol) ?> <?= number_format($quote['total'], 2) ?></span></div>
                    </div>
                </div>

                <!-- Payment Milestones -->
                <?php if (!empty($milestones)): ?>
                    <div class="fw-qi__doc-section fw-qi__milestones-view">
                        <h3>Payment Schedule</h3>
                        <table class="fw-qi__doc-table fw-qi__milestones-table">
                            <thead>
                                <tr>
                                    <th>Phase</th>
                                    <th style="text-align:right;">%</th>
                                    <th style="text-align:right;">Amount</th>
                                    <th>Due Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($milestones as $ms): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($ms['label']) ?></td>
                                        <td style="text-align:right;"><?= number_format($ms['percentage'], 1) ?>%</td>
                                        <td style="text-align:right;"><?= htmlspecialchars($docSymbol) ?> <?= number_format($ms['amount'], 2) ?></td>
                                        <td><?= $ms['due_date'] ? date('d M Y', strtotime($ms['due_date'])) : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if ($quote['terms']): ?>
                    <div class="fw-qi__doc-section">
                        <h3>Terms & Conditions</h3>
                        <p class="fw-qi__doc-terms"><?= nl2br(htmlspecialchars($quote['terms'])) ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($quote['quote_footer_text']): ?>
                    <div class="fw-qi__doc-footer"><?= nl2br(htmlspecialchars($quote['quote_footer_text'])) ?></div>
                <?php endif; ?>

            </div>
            </div><!-- /.fw-qi__doc-viewport -->
        </main>

        <footer class="fw-qi__footer">
            <span>Quote #<?= htmlspecialchars($quote['quote_number']) ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>
    </div>
    <script src="/qi/assets/qi.js?v=<?= ASSET_VERSION ?>"></script>
    <!-- Shared UI utilities -->
    <script src="/qi/assets/qi.ui.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
    window.QuoteView = {
        quoteId: <?= $quoteId ?>,
        publicToken: <?= json_encode($quote['public_token'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        
        async convertToInvoice() {
            if (!confirm('Convert this quote to an invoice?\n\n✓ Creates new invoice\n✓ Copies all items\n✓ Ready to send\n\nContinue?')) {
                return;
            }
            
            const btn = event.target;
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<span style="opacity:0.6">⏳ Converting...</span>';
            btn.disabled = true;
            
            try {
                const res = await fetch('/qi/ajax/convert_to_invoice.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({quote_id: this.quoteId})
                });
                
                const data = await res.json();
                
                if (data.ok) {
                    alert('✅ SUCCESS!\n\nInvoice created: ' + data.invoice_number + '\n\nRedirecting...');
                    window.location.href = '/qi/invoice_view.php?id=' + data.invoice_id;
                } else {
                    alert('❌ Error: ' + (data.error || 'Conversion failed'));
                    btn.innerHTML = originalHTML;
                    btn.disabled = false;
                }
            } catch (err) {
                alert('❌ Network error: ' + err.message);
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }
        },
        
        async sendQuote() {
    const customerEmail = '<?= htmlspecialchars($quote["customer_email"] ?? "") ?>';
    const customerName = '<?= htmlspecialchars($quote["customer_name"] ?? "Customer") ?>';
    
    if (!customerEmail) {
        alert('❌ No email address found for this customer.\n\nPlease add an email address in CRM first.');
        return;
    }
    
    if (!confirm('Send quote to customer?\n\n📧 To: ' + customerEmail + '\n👤 Customer: ' + customerName + '\n\nThis will mark the quote as "Sent".\n\nContinue?')) {
        return;
    }
    
    const btn = event.target;
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<span style="opacity:0.6">📧 Sending...</span>';
    btn.disabled = true;
    
    try {
        const res = await fetch('/qi/ajax/send_quote.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                quote_id: this.quoteId,
                send_to: customerEmail
            })
        });
        
        const data = await res.json();
        
        if (data.ok) {
            alert('✅ Quote sent successfully!\n\n📧 Sent to: ' + (data.recipient || customerEmail) + '\n\nStatus updated to "Sent"');
            location.reload();
        } else {
            alert('❌ Error: ' + (data.error || 'Send failed'));
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        }
    } catch (err) {
        alert('❌ Network error: ' + err.message);
        btn.innerHTML = originalHTML;
        btn.disabled = false;
    }
},
        
        downloadPDF() {
            // Stream the real PDF (download_pdf.php sends Content-Disposition:
            // attachment) — works in desktop browsers, mobile browsers, and
            // the Android WebView. Avoids window.open which is dropped by the
            // default Android WebChromeClient.
            UI.downloadFile('/qi/ajax/download_pdf.php?type=quote&id=' + this.quoteId);
        },

        printQuote() {
            // Try the printable HTML page first (browser's print dialog gives
            // pixel-perfect output on desktop). If window.open is blocked or
            // dropped (Android WebView), fall back to streaming the real PDF.
            const w = window.open('/qi/ajax/generate_pdf.php?type=quote&id=' + this.quoteId, '_blank');
            if (!w) {
                UI.downloadFile('/qi/ajax/download_pdf.php?type=quote&id=' + this.quoteId);
            }
        },
        
        async duplicateQuote() {
            if (!confirm('Create a duplicate of this quote?')) return;
            
            try {
                const res = await fetch('/qi/ajax/duplicate_quote.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({quote_id: this.quoteId})
                });
                
                const data = await res.json();
                
                if (data.ok) {
                    alert('✅ Quote duplicated!\n\nNew quote: ' + data.quote_number);
                    window.location.href = '/qi/quote_view.php?id=' + data.quote_id;
                } else {
                    alert('❌ Error: ' + (data.error || 'Duplicate failed'));
                }
            } catch (err) {
                alert('❌ Network error');
            }
        },
        copyPublicLink() {
            const baseUrl = window.location.origin || (window.location.protocol + '//' + window.location.host);
            const link = baseUrl + '/qi/p/quote.php?token=' + this.publicToken;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(link).then(() => {
                    alert('✅ Public link copied to clipboard');
                }).catch(() => {
                    prompt('Public link:', link);
                });
            } else {
                // Fallback prompt
                prompt('Public link:', link);
            }
        },
        
        async deleteQuote() {
            if (!confirm('⚠️ DELETE this quote?\n\nThis action cannot be undone!')) return;
            
            try {
                const res = await fetch('/qi/ajax/delete_quote.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({quote_id: this.quoteId})
                });
                
                const data = await res.json();
                
                if (data.ok) {
                    alert('✅ Quote deleted');
                    window.location.href = '/qi/';
                } else {
                    alert('❌ Error: ' + (data.error || 'Delete failed'));
                }
            } catch (err) {
                alert('❌ Network error');
            }
        }
        ,
        /**
         * Admin: mark the quote as accepted via token
         */
        async acceptQuote() {
            if (!this.quoteId) return;
            if (!UI.confirm('Mark this quote as ACCEPTED?')) return;
            const btn = event.target;
            const originalHTML = btn.innerHTML;
            btn.innerHTML = 'Accepting...';
            btn.disabled = true;
            try {
                const body = this.publicToken
                    ? { token: this.publicToken }
                    : { quote_id: this.quoteId };
                const res = await UI.fetchJSON('/qi/ajax/accept_quote.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });
                if (res.ok) {
                    UI.toast('Quote marked as accepted');
                    window.location.reload();
                } else {
                    UI.toast('Error: ' + (res.error || 'Could not accept'));
                    btn.innerHTML = originalHTML;
                    btn.disabled = false;
                }
            } catch (err) {
                UI.toast('Network error: ' + err.message);
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }
        },
        async declineQuote() {
            if (!this.quoteId) return;
            if (!UI.confirm('Mark this quote as DECLINED?')) return;
            const btn = event.target;
            const originalHTML = btn.innerHTML;
            btn.innerHTML = 'Declining...';
            btn.disabled = true;
            try {
                const body = this.publicToken
                    ? { token: this.publicToken }
                    : { quote_id: this.quoteId };
                const res = await UI.fetchJSON('/qi/ajax/decline_quote.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });
                if (res.ok) {
                    UI.toast('Quote marked as declined');
                    window.location.reload();
                } else {
                    UI.toast('Error: ' + (res.error || 'Could not decline'));
                    btn.innerHTML = originalHTML;
                    btn.disabled = false;
                }
            } catch (err) {
                UI.toast('Network error: ' + err.message);
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }
        }
    };
</script>
<script>
    // Pinch-to-zoom for the quote document only (app shell unaffected).
    // The document is fixed at 940px wide and CSS-zoomed via --qi-zoom.
    // Initial zoom fits it to the viewport on phones; two-finger pinch
    // updates the zoom variable live.
    (function () {
        const viewport = document.getElementById('docViewport');
        if (!viewport) return;
        const doc = viewport.querySelector('.fw-qi__document');
        if (!doc) return;

        const DOC_WIDTH = 940;
        const MIN_ZOOM = 0.25;
        const MAX_ZOOM = 3;

        function clamp(z) { return Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, z)); }
        function getZoom() {
            return parseFloat(doc.style.getPropertyValue('--qi-zoom')) || 1;
        }
        function setZoom(z) {
            doc.style.setProperty('--qi-zoom', clamp(z));
        }

        // Fit-to-screen on initial load (and only when the viewport is
        // narrower than the document — desktop stays at 1:1).
        function fitToScreen() {
            const w = viewport.clientWidth;
            if (w > 0 && w < DOC_WIDTH) {
                setZoom(w / DOC_WIDTH);
            } else {
                setZoom(1);
            }
        }
        fitToScreen();
        window.addEventListener('orientationchange', fitToScreen);

        // Two-finger pinch handling
        let pinchStartDist = 0;
        let pinchStartZoom = 1;

        function distance(t) {
            const dx = t[0].clientX - t[1].clientX;
            const dy = t[0].clientY - t[1].clientY;
            return Math.hypot(dx, dy);
        }

        viewport.addEventListener('touchstart', function (e) {
            if (e.touches.length === 2) {
                pinchStartDist = distance(e.touches);
                pinchStartZoom = getZoom();
                e.preventDefault();
            }
        }, { passive: false });

        viewport.addEventListener('touchmove', function (e) {
            if (e.touches.length === 2 && pinchStartDist > 0) {
                const d = distance(e.touches);
                setZoom(pinchStartZoom * (d / pinchStartDist));
                e.preventDefault();
            }
        }, { passive: false });

        viewport.addEventListener('touchend', function (e) {
            if (e.touches.length < 2) {
                pinchStartDist = 0;
            }
        });
    })();
</script>
</body>
</html>