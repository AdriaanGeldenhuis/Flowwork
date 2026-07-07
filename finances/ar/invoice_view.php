<?php
// /finances/ar/invoice_view.php — SARS-Compliant Tax Invoice View
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['viewer', 'bookkeeper', 'admin']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #<?= $id ?> – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-finance">
    <div class="fw-finance__container">

            <?php
            $finTitle = 'Tax Invoice';
            $finBack = '/finances/ar/';
            $finCompanyName = $companyName;
            $finFirstName = $firstName;
            include __DIR__ . '/../partials/header.php';
            ?>

            <!-- Main Content -->
            <main class="fw-finance__main">

                <!-- Action Bar -->
                <div class="fw-finance__invoice-actions">
                    <a href="/finances/ar/" class="fw-finance__btn fw-finance__btn--secondary">
                        <svg viewBox="0 0 24 24" fill="none" width="16" height="16" style="vertical-align:middle;margin-right:4px">
                            <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Back to Invoices
                    </a>
                    <div class="fw-finance__invoice-actions-right">
                        <a id="qiLink" href="#" class="fw-finance__btn fw-finance__btn--secondary" target="_blank">Open in Q&I</a>
                        <span id="journalLink"></span>
                        <button onclick="window.print()" class="fw-finance__btn fw-finance__btn--primary">
                            <svg viewBox="0 0 24 24" fill="none" width="16" height="16" style="vertical-align:middle;margin-right:4px">
                                <path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <rect x="6" y="14" width="12" height="8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            Print Invoice
                        </button>
                    </div>
                </div>

                <!-- Invoice Container -->
                <div class="fw-finance__invoice" id="invoiceContainer">
                    <div class="fw-finance__loading" id="invoiceLoading">Loading invoice...</div>
                </div>

            </main>

            <!-- Footer -->
            <footer class="fw-finance__footer">
                <span>Tax Invoice v<?= ASSET_VERSION ?></span>
                <span id="themeIndicator">Theme: Light</span>
            </footer>

    </div>

    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
    (async function() {
      'use strict';

      function esc(s) {
        const div = document.createElement('div');
        div.textContent = s || '';
        return div.innerHTML;
      }

      function fmt(n) {
        return new Intl.NumberFormat('en-ZA', {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        }).format(Number(n || 0));
      }

      function fmtDate(d) {
        if (!d) return '-';
        const dt = new Date(d + 'T00:00:00');
        return dt.toLocaleDateString('en-ZA', { day: 'numeric', month: 'short', year: 'numeric' });
      }

      function buildAddress(addr) {
        if (!addr) return '';
        const parts = [addr.line1, addr.line2, addr.city, addr.region, addr.postal_code].filter(Boolean);
        return parts.join(', ');
      }

      function buildCompanyAddress(c) {
        if (!c) return '';
        const parts = [c.address_line1, c.address_line2, c.city, c.region, c.postal].filter(Boolean);
        return parts.join(', ');
      }

      function statusLabel(s) {
        const map = { draft: 'Draft', sent: 'Sent', viewed: 'Viewed', paid: 'Paid', overdue: 'Overdue', cancelled: 'Cancelled' };
        return map[s] || s || 'Unknown';
      }

      const container = document.getElementById('invoiceContainer');

      try {
        const res = await fetch('/finances/ajax/ar_invoice_view.php?id=<?= $id ?>', {
          headers: { 'Accept': 'application/json' }
        });
        const json = await res.json();

        if (!json.ok) {
          container.innerHTML = '<div class="fw-finance__empty-state">Error: ' + esc(json.error || 'Failed to load invoice') + '</div>';
          return;
        }

        const d = json.data;
        const c = d.company || {};

        document.title = 'Invoice ' + (d.invoice_number || '') + ' - ' + esc(c.name || 'Finances');

        // Build Q&I link
        document.getElementById('qiLink').href = '/qi/invoice_view.php?id=' + encodeURIComponent(d.id);

        // Build Journal link
        if (d.journal_id) {
          document.getElementById('journalLink').innerHTML =
            '<a href="/finances/gl/journal_view.php?id=' + encodeURIComponent(d.journal_id) +
            '" class="fw-finance__btn fw-finance__btn--secondary">Journal #' + esc(String(d.journal_id)) + '</a>';
        }

        // Build line items rows
        const linesHtml = (d.lines || []).map(function(l) {
          return '<tr>' +
            '<td>' + esc(l.item_description) + '</td>' +
            '<td class="num">' + esc(String(l.quantity)) + '</td>' +
            '<td>' + esc(l.unit || '') + '</td>' +
            '<td class="num">R ' + fmt(l.unit_price) + '</td>' +
            '<td class="num">' + fmt(l.tax_rate) + '%</td>' +
            '<td class="num">R ' + fmt(l.line_total) + '</td>' +
          '</tr>';
        }).join('');

        // Build customer address
        const custAddr = buildAddress(d.customer_address);
        const compAddr = buildCompanyAddress(c);

        // Render full invoice
        container.innerHTML = '' +
          // Tax Invoice Header
          '<div class="fw-finance__invoice-type">Tax Invoice</div>' +

          // Seller / Buyer
          '<div class="fw-finance__invoice-parties">' +
            '<div class="fw-finance__invoice-party">' +
              '<div class="fw-finance__invoice-party-label">From (Seller)</div>' +
              '<div class="fw-finance__invoice-party-name">' + esc(c.name) + '</div>' +
              '<div class="fw-finance__invoice-party-detail">' +
                (compAddr ? esc(compAddr) + '<br>' : '') +
                (c.phone ? '<strong>Tel:</strong> ' + esc(c.phone) + '<br>' : '') +
                (c.email ? '<strong>Email:</strong> ' + esc(c.email) + '<br>' : '') +
                (c.vat_number ? '<strong>VAT No:</strong> ' + esc(c.vat_number) + '<br>' : '') +
                (c.tax_number ? '<strong>Tax No:</strong> ' + esc(c.tax_number) + '<br>' : '') +
                (c.reg_number ? '<strong>Reg No:</strong> ' + esc(c.reg_number) : '') +
              '</div>' +
            '</div>' +
            '<div class="fw-finance__invoice-party">' +
              '<div class="fw-finance__invoice-party-label">To (Buyer)</div>' +
              '<div class="fw-finance__invoice-party-name">' + esc(d.customer_name) + '</div>' +
              '<div class="fw-finance__invoice-party-detail">' +
                (custAddr ? esc(custAddr) + '<br>' : '') +
                (d.customer_phone ? '<strong>Tel:</strong> ' + esc(d.customer_phone) + '<br>' : '') +
                (d.customer_email ? '<strong>Email:</strong> ' + esc(d.customer_email) + '<br>' : '') +
                (d.customer_vat ? '<strong>VAT No:</strong> ' + esc(d.customer_vat) : '') +
              '</div>' +
            '</div>' +
          '</div>' +

          // Invoice Metadata
          '<div class="fw-finance__invoice-meta">' +
            '<div class="fw-finance__invoice-meta-item">' +
              '<div class="fw-finance__invoice-meta-label">Invoice Number</div>' +
              '<div class="fw-finance__invoice-meta-value">' + esc(d.invoice_number) + '</div>' +
            '</div>' +
            '<div class="fw-finance__invoice-meta-item">' +
              '<div class="fw-finance__invoice-meta-label">Issue Date</div>' +
              '<div class="fw-finance__invoice-meta-value">' + fmtDate(d.issue_date) + '</div>' +
            '</div>' +
            '<div class="fw-finance__invoice-meta-item">' +
              '<div class="fw-finance__invoice-meta-label">Due Date</div>' +
              '<div class="fw-finance__invoice-meta-value">' + fmtDate(d.due_date) + '</div>' +
            '</div>' +
            '<div class="fw-finance__invoice-meta-item">' +
              '<div class="fw-finance__invoice-meta-label">Status</div>' +
              '<div class="fw-finance__invoice-meta-value">' +
                '<span class="fw-finance__badge fw-finance__badge--' + esc(d.status) + '">' + statusLabel(d.status) + '</span>' +
              '</div>' +
            '</div>' +
            '<div class="fw-finance__invoice-meta-item">' +
              '<div class="fw-finance__invoice-meta-label">Currency</div>' +
              '<div class="fw-finance__invoice-meta-value">' + esc(d.currency || 'ZAR') + '</div>' +
            '</div>' +
          '</div>' +

          // Line Items Table
          '<table class="fw-finance__invoice-table">' +
            '<thead><tr>' +
              '<th>Description</th>' +
              '<th class="num">Qty</th>' +
              '<th>Unit</th>' +
              '<th class="num">Unit Price (excl. VAT)</th>' +
              '<th class="num">VAT %</th>' +
              '<th class="num">Line Total (incl. VAT)</th>' +
            '</tr></thead>' +
            '<tbody>' + linesHtml + '</tbody>' +
          '</table>' +

          // Totals
          '<div class="fw-finance__invoice-totals">' +
            '<table class="fw-finance__invoice-totals-table">' +
              '<tr><td>Subtotal (excl. VAT)</td><td>R ' + fmt(d.subtotal) + '</td></tr>' +
              (parseFloat(d.discount) > 0 ? '<tr><td>Discount</td><td>R ' + fmt(d.discount) + '</td></tr>' : '') +
              '<tr><td>VAT</td><td>R ' + fmt(d.tax) + '</td></tr>' +
              '<tr class="total"><td>Total (incl. VAT)</td><td>R ' + fmt(d.total) + '</td></tr>' +
              '<tr class="balance"><td>Balance Due</td><td>R ' + fmt(d.balance_due) + '</td></tr>' +
            '</table>' +
          '</div>' +

          // Payment Terms
          (d.terms ? '<div class="fw-finance__invoice-section">' +
            '<div class="fw-finance__invoice-section-title">Payment Terms</div>' +
            '<div class="fw-finance__invoice-section-body">' + esc(d.terms) + '</div>' +
          '</div>' : '') +

          // Bank Details
          (c.bank_name ? '<div class="fw-finance__invoice-section">' +
            '<div class="fw-finance__invoice-section-title">Banking Details</div>' +
            '<div class="fw-finance__invoice-section-body">' +
              '<strong>Bank:</strong> ' + esc(c.bank_name) + '<br>' +
              (c.bank_account_number ? '<strong>Account No:</strong> ' + esc(c.bank_account_number) + '<br>' : '') +
              (c.bank_branch_code ? '<strong>Branch Code:</strong> ' + esc(c.bank_branch_code) + '<br>' : '') +
              '<strong>Reference:</strong> ' + esc(d.invoice_number) +
            '</div>' +
          '</div>' : '') +

          // Notes
          (d.notes ? '<div class="fw-finance__invoice-section">' +
            '<div class="fw-finance__invoice-section-title">Notes</div>' +
            '<div class="fw-finance__invoice-section-body">' + esc(d.notes) + '</div>' +
          '</div>' : '') +

          // Footer Text
          (c.invoice_footer_text ? '<div class="fw-finance__invoice-section">' +
            '<div class="fw-finance__invoice-section-body" style="text-align:center;font-size:12px;color:var(--fw-text-muted)">' +
              esc(c.invoice_footer_text) +
            '</div>' +
          '</div>' : '');

      } catch (e) {
        console.error('Invoice load failed:', e);
        container.innerHTML = '<div class="fw-finance__empty-state">Failed to load invoice. Please try again.</div>';
      }
    })();
    </script>
</body>
</html>
