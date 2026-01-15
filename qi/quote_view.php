<?php
// /qi/quote_view.php - COMPLETE WITH CONVERSION
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-QI-FINAL');

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
               ca.name AS customer_name,
               ca.email AS customer_email,
               ca.phone AS customer_phone,
               p.name AS project_name
        FROM quotes q
        LEFT JOIN users u ON q.created_by = u.id
        LEFT JOIN companies c ON q.company_id = c.id
        LEFT JOIN crm_accounts ca ON q.customer_id = ca.id
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

    $stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $firstName = $user['first_name'] ?? 'User';

} catch (Exception $e) {
    die('Database error: ' . $e->getMessage());
}

$canEdit = in_array($quote['status'], ['draft']);
$canSend = in_array($quote['status'], ['draft', 'sent']);
$canConvert = $quote['status'] === 'accepted';

$primaryColor = $quote['primary_color'] ?? '#fbbf24';
$secondaryColor = $quote['secondary_color'] ?? '#f59e0b';
$fontFamily = $quote['qi_font_family'] ?? 'system-ui';

$fontMap = [
    'system-ui' => 'system-ui, -apple-system, sans-serif',
    'montserrat' => "'Montserrat', sans-serif",
    'helvetica' => "'Helvetica Neue', Helvetica, Arial, sans-serif",
    'georgia' => "Georgia, serif",
    'inter' => "'Inter', sans-serif"
];
$fontStack = $fontMap[$fontFamily] ?? $fontMap['system-ui'];

$showAddress = (int)($quote['qi_show_company_address'] ?? 1);
$showPhone = (int)($quote['qi_show_company_phone'] ?? 1);
$showEmail = (int)($quote['qi_show_company_email'] ?? 1);
$showWebsite = (int)($quote['qi_show_company_website'] ?? 1);
$showVat = (int)($quote['qi_show_vat_number'] ?? 1);
$showTax = (int)($quote['qi_show_tax_number'] ?? 1);
$showReg = (int)($quote['qi_show_reg_number'] ?? 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($quote['quote_number']) ?> – <?= htmlspecialchars($quote['company_name']) ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="/qi/assets/qi.css?v=<?= ASSET_VERSION ?>">
    <link rel="stylesheet" href="/qi/assets/templates-pro.css?v=<?= ASSET_VERSION ?>">
    
    <style>
        :root {
            --accent-qi: <?= $primaryColor ?>;
            --accent-qi-secondary: <?= $secondaryColor ?>;
            --doc-font: <?= $fontStack ?>;
        }
        .fw-qi__document, .fw-qi__document * { font-family: <?= $fontStack ?> !important; }
        .fw-qi__doc-title { color: <?= $primaryColor ?> !important; }
        .fw-qi__doc-company h1 { color: <?= $primaryColor ?> !important; }
        .fw-qi__doc-company strong { color: <?= $primaryColor ?> !important; }
        .fw-qi__doc-table thead { background: <?= $primaryColor ?> !important; }
        .fw-qi__doc-header { border-bottom-color: <?= $primaryColor ?> !important; }
        .fw-qi__doc-section h3 { border-bottom-color: <?= $primaryColor ?> !important; }
        .fw-qi__doc-detail-box { border-left-color: <?= $primaryColor ?> !important; }
        .fw-qi__doc-detail-box h3 { color: <?= $primaryColor ?> !important; }
        .fw-qi__doc-total-row--grand { color: <?= $primaryColor ?> !important; border-top-color: <?= $primaryColor ?> !important; }
        @media print {
            .fw-qi__doc-table thead { background: <?= $primaryColor ?> !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .fw-qi__doc-title { color: <?= $primaryColor ?> !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .fw-qi__doc-company h1 { color: <?= $primaryColor ?> !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
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
        
        <button onclick="window.print()" class="fw-qi__kebab-item">
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
        
        <?php if ($quote['status'] === 'draft'): ?>
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
            <div class="fw-qi__document">
                
                <div class="fw-qi__doc-header">
                    <div class="fw-qi__doc-header-left">
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
                            <?php if ($showReg && $quote['reg_number']): ?><p><strong>Reg:</strong> <?= htmlspecialchars($quote['reg_number']) ?></p><?php endif; ?>
                            <?php if ($showTax && $quote['tax_number']): ?><p><strong>Tax:</strong> <?= htmlspecialchars($quote['tax_number']) ?></p><?php endif; ?>
                            <?php if ($showVat && $quote['vat_number']): ?><p><strong>VAT:</strong> <?= htmlspecialchars($quote['vat_number']) ?></p><?php endif; ?>
                            <?php if ($showPhone && $quote['company_phone']): ?><p><?= htmlspecialchars($quote['company_phone']) ?></p><?php endif; ?>
                            <?php if ($showEmail && $quote['company_email']): ?><p><?= htmlspecialchars($quote['company_email']) ?></p><?php endif; ?>
                            <?php if ($showWebsite && $quote['website']): ?><p><?= htmlspecialchars($quote['website']) ?></p><?php endif; ?>
                        </div>
                    </div>
                    <div class="fw-qi__doc-header-right">
                        <h2 class="fw-qi__doc-title">QUOTATION</h2>
                        <div class="fw-qi__doc-number"><?= htmlspecialchars($quote['quote_number']) ?></div>
                        <span class="fw-qi__badge fw-qi__badge--<?= $quote['status'] ?>"><?= strtoupper($quote['status']) ?></span>
                    </div>
                </div>

                <div class="fw-qi__doc-details">
                    <div class="fw-qi__doc-detail-box">
                        <h3>Bill To:</h3>
                        <p><strong><?= htmlspecialchars($quote['customer_name'] ?? 'No Customer') ?></strong></p>
                        <?php if ($quote['customer_email']): ?><p><?= htmlspecialchars($quote['customer_email']) ?></p><?php endif; ?>
                        <?php if ($quote['customer_phone']): ?><p><?= htmlspecialchars($quote['customer_phone']) ?></p><?php endif; ?>
                    </div>
                    <div class="fw-qi__doc-detail-box">
                        <table class="fw-qi__doc-info-table">
                            <tr><td>Quote Date:</td><td><strong><?= date('d M Y', strtotime($quote['issue_date'])) ?></strong></td></tr>
                            <tr><td>Valid Until:</td><td><strong><?= date('d M Y', strtotime($quote['expiry_date'])) ?></strong></td></tr>
                            <?php if ($quote['project_name']): ?><tr><td>Project:</td><td><strong><?= htmlspecialchars($quote['project_name']) ?></strong></td></tr><?php endif; ?>
                            <tr><td>Created By:</td><td><?= htmlspecialchars($quote['creator_first_name'] . ' ' . $quote['creator_last_name']) ?></td></tr>
                        </table>
                    </div>
                </div>

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
                                        <td class="fw-qi__doc-table-right">R <?= number_format($line['unit_price'], 2) ?></td>
                                        <td class="fw-qi__doc-table-right"><strong>R <?= number_format($line['line_total'], 2) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align:center;padding:40px;color:#999;">No items</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div class="fw-qi__doc-totals">
                        <div class="fw-qi__doc-total-row"><span>Subtotal:</span><span>R <?= number_format($quote['subtotal'], 2) ?></span></div>
                        <div class="fw-qi__doc-total-row"><span>Discount:</span><span>R <?= number_format($quote['discount'], 2) ?></span></div>
                        <div class="fw-qi__doc-total-row"><span>VAT:</span><span>R <?= number_format($quote['tax'], 2) ?></span></div>
                        <div class="fw-qi__doc-total-row fw-qi__doc-total-row--grand"><span>TOTAL:</span><span>R <?= number_format($quote['total'], 2) ?></span></div>
                    </div>
                </div>

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
        publicToken: '<?= addslashes($quote['public_token'] ?? '') ?>',
        
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
        console.error('Send error:', err);
        alert('❌ Network error: ' + err.message);
        btn.innerHTML = originalHTML;
        btn.disabled = false;
    }
},
        
        downloadPDF() {
            window.open('/qi/ajax/generate_pdf.php?type=quote&id=' + this.quoteId, '_blank');
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
            if (!this.publicToken) return;
            if (!UI.confirm('Mark this quote as ACCEPTED?')) return;
            const btn = event.target;
            const originalHTML = btn.innerHTML;
            btn.innerHTML = 'Accepting...';
            btn.disabled = true;
            try {
                const res = await UI.fetchJSON('/qi/ajax/accept_quote.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: this.publicToken })
                });
                if (res.ok) {
                    UI.toast('✅ Quote marked as accepted');
                    window.location.reload();
                } else {
                    UI.toast('❌ Error: ' + (res.error || 'Could not accept'));
                    btn.innerHTML = originalHTML;
                    btn.disabled = false;
                }
            } catch (err) {
                UI.toast('❌ Network error: ' + err.message);
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }
        },
        /**
         * Admin: mark the quote as declined via token
         */
        async declineQuote() {
            if (!this.publicToken) return;
            if (!UI.confirm('Mark this quote as DECLINED?')) return;
            const btn = event.target;
            const originalHTML = btn.innerHTML;
            btn.innerHTML = 'Declining...';
            btn.disabled = true;
            try {
                const res = await UI.fetchJSON('/qi/ajax/decline_quote.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: this.publicToken })
                });
                if (res.ok) {
                    UI.toast('✅ Quote marked as declined');
                    window.location.reload();
                } else {
                    UI.toast('❌ Error: ' + (res.error || 'Could not decline'));
                    btn.innerHTML = originalHTML;
                    btn.disabled = false;
                }
            } catch (err) {
                UI.toast('❌ Network error: ' + err.message);
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }
        }
    };
</script>
</body>
</html>