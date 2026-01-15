<?php
// Public quote view – allows clients to view, accept or decline a quote via a token link
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../init.php';

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
               p.name AS project_name
        FROM quotes q
        LEFT JOIN companies c ON q.company_id = c.id
        LEFT JOIN crm_accounts ca ON q.customer_id = ca.id
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

    // Determine settings
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
    <link rel="stylesheet" href="/qi/assets/qi.css?v=2025-01-21-QI-PUBLIC">
    <link rel="stylesheet" href="/qi/assets/templates-pro.css?v=2025-01-21-QI-PUBLIC">
    <style>
        :root {
            --accent-qi: <?= $primaryColor ?>;
            --accent-qi-secondary: <?= $secondaryColor ?>;
            --doc-font: <?= $fontStack ?>;
        }
        .fw-qi__document, .fw-qi__document * { font-family: <?= $fontStack ?> !important; }
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
            <div class="fw-qi__document">
                <div class="fw-qi__doc-header">
                    <div class="fw-qi__doc-header-left">
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
                            <?php if ($showReg && $quote['reg_number']): ?><p><strong>Reg:</strong> <?= htmlspecialchars($quote['reg_number']) ?></p><?php endif; ?>
                            <?php if ($showTax && $quote['tax_number']): ?><p><strong>Tax:</strong> <?= htmlspecialchars($quote['tax_number']) ?></p><?php endif; ?>
                            <?php if ($showVat && $quote['vat_number']): ?><p><strong>VAT:</strong> <?= htmlspecialchars($quote['vat_number']) ?></p><?php endif; ?>
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
                    </div>
                </div>
                <div class="fw-qi__doc-section">
                    <h3>Quote To</h3>
                    <p><strong><?= htmlspecialchars($quote['customer_name']) ?></strong></p>
                    <?php if ($quote['customer_email']): ?><p><?= htmlspecialchars($quote['customer_email']) ?></p><?php endif; ?>
                    <?php if ($quote['customer_phone']): ?><p><?= htmlspecialchars($quote['customer_phone']) ?></p><?php endif; ?>
                    <?php if ($quote['project_name']): ?><p><em>Project: <?= htmlspecialchars($quote['project_name']) ?></em></p><?php endif; ?>
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
                        <?php $tax = $subtotal * 0.15; $total = $subtotal + $tax; ?>
                        <div class="fw-qi__doc-total-row"><span>Subtotal:</span><span>R <?= number_format($subtotal, 2) ?></span></div>
                        <div class="fw-qi__doc-total-row"><span>VAT (15%):</span><span>R <?= number_format($tax, 2) ?></span></div>
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