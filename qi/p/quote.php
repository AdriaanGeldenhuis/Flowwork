<?php
// Public quote view – allows clients to view, accept or decline a quote via a token link
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../lib/Branding.php';

// Get token from query string
$token = $_GET['token'] ?? '';
if (!$token) {
    echo '<h1>Invalid token</h1>';
    exit;
}

try {
    // Fetch quote and related info by public token
    $stmt = $DB->prepare("
        SELECT q.*, 
               c.name AS company_name,
               c.logo_url,
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
               c.qi_template,
               c.qi_quote_title,
               c.qi_heading_color,
               c.qi_text_color,
               c.qi_table_header_text,
               c.qi_bg_color,
               c.qi_border_radius,
               c.qi_logo_size,
               c.qi_logo_position,
               c.qi_custom_css,
               c.address_line1 AS company_address1,
               c.address_line2 AS company_address2,
               c.city AS company_city,
               c.region AS company_region,
               c.postal AS company_postal,
               c.phone AS company_phone,
               c.email AS company_email,
               c.website,
               c.vat_number,
               c.tax_number,
               c.reg_number,
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
        LEFT JOIN companies c ON q.company_id = c.id
        LEFT JOIN crm_accounts ca ON q.customer_id = ca.id
        LEFT JOIN crm_addresses addr ON addr.account_id = ca.id AND addr.id = (SELECT a2.id FROM crm_addresses a2 WHERE a2.account_id = ca.id ORDER BY FIELD(a2.type, 'billing', 'head_office', 'shipping', 'site') LIMIT 1)
        LEFT JOIN projects p ON q.project_id = p.project_id
        WHERE q.public_token = ?
    ");
    $stmt->execute([$token]);
    $quote = $stmt->fetch();

    if (!$quote) {
        echo '<h1>Quote not found</h1>';
        exit;
    }

    // Fetch line items
    $stmt = $DB->prepare("SELECT * FROM quote_lines WHERE quote_id = ? ORDER BY sort_order");
    $stmt->execute([$quote['id']]);
    $lines = $stmt->fetchAll();

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

} catch (Exception $e) {
    echo '<h1>Database error</h1>';
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($quote['quote_number']) ?> – <?= htmlspecialchars($quote['company_name']) ?></title>
    <?= Branding::fontHeadLinks() ?>

    <link rel="stylesheet" href="/qi/assets/qi.css?v=<?= htmlspecialchars(CRM_ASSET_VERSION) ?>">
    <link rel="stylesheet" href="/qi/assets/templates-pro.css?v=<?= htmlspecialchars(CRM_ASSET_VERSION) ?>">
    <style>
<?= Branding::documentStyle($brand) ?>
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
                    <div class="fw-qi__app-name">Quote <?= htmlspecialchars($quote['quote_number']) ?></div>
                </div>
            </div>
        </header>

        <main class="fw-qi__main">
            <div class="fw-qi__document" data-template="<?= $template ?>">
                <div class="fw-qi__doc-header">
                    <div class="fw-qi__doc-header-left<?= $logoPosition !== 'left' ? ' fw-qi__doc-header-left--' . $logoPosition : '' ?>">
                        <?php if ($quote['logo_url']): ?>
                            <img src="<?= htmlspecialchars($quote['logo_url']) ?>" alt="Logo" class="fw-qi__doc-logo">
                        <?php endif; ?>
                        <div class="fw-qi__doc-company">
                            <h1><?= htmlspecialchars($quote['company_name']) ?></h1>
                            <?php if ($showAddress): ?>
                                <?php if ($quote['address_line1']): ?><p><?= htmlspecialchars($quote['address_line1']) ?></p><?php endif; ?>
                                <?php if ($quote['address_line2']): ?><p><?= htmlspecialchars($quote['address_line2']) ?></p><?php endif; ?>
                                <?php if ($quote['city']): ?><p><?= htmlspecialchars($quote['city']) ?>, <?= htmlspecialchars($quote['postal']) ?></p><?php endif; ?>
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
                        <div class="fw-qi__doc-title">QUOTATION</div>
                        <div class="fw-qi__doc-ref"><strong>Quote #:</strong> <?= htmlspecialchars($quote['quote_number']) ?></div>
                        <div class="fw-qi__doc-ref"><strong>Date:</strong> <?= htmlspecialchars($quote['issue_date']) ?></div>
                        <div class="fw-qi__doc-ref"><strong>Valid Until:</strong> <?= htmlspecialchars($quote['expiry_date']) ?></div>

                        <div style="margin-top:18px;padding-top:14px;border-top:1px solid rgba(0,0,0,0.08);">
                            <h3 style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--qi-heading-color);margin:0 0 8px 0;">Quote To</h3>
                            <p style="margin:4px 0;"><strong><?= htmlspecialchars($quote['customer_name']) ?></strong></p>
                            <?php if (!empty($quote['customer_address1'])): ?><p style="margin:3px 0;"><?= htmlspecialchars($quote['customer_address1']) ?></p><?php endif; ?>
                            <?php if (!empty($quote['customer_address2'])): ?><p style="margin:3px 0;"><?= htmlspecialchars($quote['customer_address2']) ?></p><?php endif; ?>
                            <?php $custCity = trim(($quote['customer_city'] ?? '') . ', ' . ($quote['customer_region'] ?? '') . ' ' . ($quote['customer_postal'] ?? '')); ?>
                            <?php if ($custCity && $custCity !== ', '): ?><p style="margin:3px 0;"><?= htmlspecialchars($custCity) ?></p><?php endif; ?>
                            <?php if ($quote['customer_phone']): ?><p style="margin:3px 0;">Tel: <?= htmlspecialchars($quote['customer_phone']) ?></p><?php endif; ?>
                            <?php if ($quote['customer_email']): ?><p style="margin:3px 0;">Email: <?= htmlspecialchars($quote['customer_email']) ?></p><?php endif; ?>
                            <?php if (!empty($quote['customer_vat'])): ?><p style="margin:3px 0;">VAT No: <?= htmlspecialchars($quote['customer_vat']) ?></p><?php endif; ?>
                            <?php if (!empty($quote['customer_reg'])): ?><p style="margin:3px 0;">Reg No: <?= htmlspecialchars($quote['customer_reg']) ?></p><?php endif; ?>
                            <?php if ($quote['project_name']): ?><p style="margin:3px 0;"><em>Project: <?= htmlspecialchars($quote['project_name']) ?></em></p><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="fw-qi__doc-section">
                    <table class="fw-qi__doc-table">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th style="text-align:right">Qty</th>
                                <th style="text-align:right">Unit Price</th>
                                <th style="text-align:right">Line Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $subtotal = 0; ?>
                            <?php foreach ($lines as $line): ?>
                                <?php
                                    $qty = (float)$line['quantity'];
                                    $price = (float)$line['unit_price'];
                                    $lineTotal = $qty * $price;
                                    $subtotal += $lineTotal;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($line['item_description']) ?></td>
                                    <td style="text-align:right;"><?= number_format($qty, 2) ?></td>
                                    <td style="text-align:right;">R <?= number_format($price, 2) ?></td>
                                    <td style="text-align:right;">R <?= number_format($lineTotal, 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="fw-qi__doc-totals">
                        <?php $tax = (float)$quote['tax']; $total = (float)$quote['total']; ?>
                        <div class="fw-qi__doc-total-row"><span>Subtotal:</span><span>R <?= number_format($subtotal, 2) ?></span></div>
                        <div class="fw-qi__doc-total-row"><span>VAT:</span><span>R <?= number_format($tax, 2) ?></span></div>
                        <div class="fw-qi__doc-total-row fw-qi__doc-total-row--grand"><span>TOTAL:</span><span>R <?= number_format($total, 2) ?></span></div>
                    </div>
                </div>
                <?php if ($quote['terms']): ?>
                    <div class="fw-qi__doc-section">
                        <h3>Terms & Conditions</h3>
                        <p><?= nl2br(htmlspecialchars($quote['terms'])) ?></p>
                    </div>
                <?php endif; ?>
                <?php if ($quote['notes']): ?>
                    <div class="fw-qi__doc-section">
                        <h3>Notes</h3>
                        <p><?= nl2br(htmlspecialchars($quote['notes'])) ?></p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="fw-qi__public-actions" style="margin-top:24px;text-align:center;">
                <?php if ($quote['status'] !== 'accepted' && $quote['status'] !== 'declined'): ?>
                    <button id="acceptBtn" class="fw-qi__btn fw-qi__btn--primary" style="margin-right:12px;">Accept Quote</button>
                    <button id="declineBtn" class="fw-qi__btn fw-qi__btn--secondary">Decline Quote</button>
                <?php else: ?>
                    <?php if ($quote['status'] === 'accepted'): ?>
                        <p style="color:var(--accent-qi);font-weight:600;">This quote was accepted on <?= htmlspecialchars($quote['accepted_at'] ?? '') ?>.</p>
                    <?php else: ?>
                        <p style="color:#ef4444;font-weight:600;">This quote was declined on <?= htmlspecialchars($quote['declined_at'] ?? '') ?>.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </main>
        <footer class="fw-qi__footer" style="text-align:center;">
            <span>Generated by Quotes & Invoices</span>
        </footer>
    </div>
    <script>
    (function() {
        const token = <?= json_encode($token) ?>;
        function handleResponse(res) {
            if (res.ok) {
                alert(res.message || 'Thank you');
                location.reload();
            } else {
                alert(res.error || 'Action failed');
            }
        }
        async function acceptQuote() {
            if (!confirm('Are you sure you want to accept this quote?')) return;
            try {
                const response = await fetch('/qi/ajax/accept_quote.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: token })
                });
                const data = await response.json();
                handleResponse(data);
            } catch (err) {
                alert('Network error: ' + err.message);
            }
        }
        async function declineQuote() {
            if (!confirm('Do you want to decline this quote?')) return;
            try {
                const response = await fetch('/qi/ajax/decline_quote.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: token })
                });
                const data = await response.json();
                handleResponse(data);
            } catch (err) {
                alert('Network error: ' + err.message);
            }
        }
        document.getElementById('acceptBtn')?.addEventListener('click', acceptQuote);
        document.getElementById('declineBtn')?.addEventListener('click', declineQuote);
    })();
    </script>
</body>
</html>