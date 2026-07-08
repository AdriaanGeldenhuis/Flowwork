<?php
// /finances/gl/journal_view.php — Standalone Journal Entry View
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: /finances/journals.php');
    exit;
}

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
    <title>Journal #<?= $id ?> – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-finance">
    <div class="fw-finance__container">

            <!-- Header -->
            <?php $finTitle = 'Journal Entry'; $finBack = '/finances/journals.php'; $finCompanyName = $companyName; $finFirstName = $firstName; include __DIR__ . '/../partials/header.php'; ?>

            <!-- Main Content -->
            <main class="fw-finance__main">

                <!-- Action Bar -->
                <div class="fw-finance__invoice-actions">
                    <a href="/finances/journals.php" class="fw-finance__btn fw-finance__btn--secondary">
                        <svg viewBox="0 0 24 24" fill="none" width="16" height="16" style="vertical-align:middle;margin-right:4px">
                            <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Back to Journals
                    </a>
                    <div class="fw-finance__invoice-actions-right">
                        <button onclick="window.print()" class="fw-finance__btn fw-finance__btn--primary">
                            <svg viewBox="0 0 24 24" fill="none" width="16" height="16" style="vertical-align:middle;margin-right:4px">
                                <path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <rect x="6" y="14" width="12" height="8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            Print
                        </button>
                    </div>
                </div>

                <!-- Journal Container -->
                <div class="fw-finance__invoice" id="journalContainer">
                    <div class="fw-finance__loading">Loading journal entry...</div>
                </div>

            </main>

            <!-- Footer -->
            <footer class="fw-finance__footer">
                <span>Journal Entry v<?= ASSET_VERSION ?></span>
                <span id="themeIndicator">Theme: Light</span>
            </footer>

    </div>

    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
    (async function() {
      'use strict';

      function esc(s) {
        var div = document.createElement('div');
        div.textContent = s || '';
        return div.innerHTML;
      }

      function fmtDate(d) {
        if (!d) return '-';
        var dt = new Date(d.indexOf('T') >= 0 ? d : d + 'T00:00:00');
        return dt.toLocaleDateString('en-ZA', { day: 'numeric', month: 'short', year: 'numeric' });
      }

      function fmtCurrency(n) {
        return new Intl.NumberFormat('en-ZA', {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        }).format(Number(n || 0));
      }

      function statusLabel(s) {
        var map = { draft: 'Draft', approved: 'Approved', posted: 'Posted' };
        return map[s] || s || 'Unknown';
      }

      function statusClass(s) {
        var map = { draft: 'draft', approved: 'approved', posted: 'posted' };
        return map[s] || 'draft';
      }

      var container = document.getElementById('journalContainer');

      try {
        var res = await fetch('/finances/ajax/journal_get.php?journal_id=<?= $id ?>', {
          headers: { 'Accept': 'application/json' }
        });
        var json = await res.json();

        if (!json.ok) {
          container.innerHTML = '<div class="fw-finance__empty-state">Error: ' + esc(json.error || 'Failed to load journal') + '</div>';
          return;
        }

        var j = json.data;
        var lines = j.lines || [];
        var createdBy = [j.first_name, j.last_name].filter(Boolean).join(' ') || 'Unknown';

        document.title = 'Journal #' + j.journal_id + ' - <?= htmlspecialchars(addslashes($companyName)) ?>';

        // Build header metadata
        var headerHtml = '<div class="fw-finance__invoice-type">Journal Entry #' + esc(String(j.journal_id)) + '</div>';

        headerHtml += '<div class="fw-finance__invoice-meta">';
        headerHtml += '<div class="fw-finance__invoice-meta-item"><div class="fw-finance__invoice-meta-label">Date</div><div class="fw-finance__invoice-meta-value">' + fmtDate(j.entry_date) + '</div></div>';
        headerHtml += '<div class="fw-finance__invoice-meta-item"><div class="fw-finance__invoice-meta-label">Status</div><div class="fw-finance__invoice-meta-value"><span class="fw-finance__journal-status fw-finance__journal-status--' + statusClass(j.status) + '">' + statusLabel(j.status) + '</span></div></div>';
        headerHtml += '<div class="fw-finance__invoice-meta-item"><div class="fw-finance__invoice-meta-label">Reference</div><div class="fw-finance__invoice-meta-value">' + (esc(j.reference) || '-') + '</div></div>';
        headerHtml += '<div class="fw-finance__invoice-meta-item"><div class="fw-finance__invoice-meta-label">Module</div><div class="fw-finance__invoice-meta-value">' + (esc(j.module) || '-') + '</div></div>';
        headerHtml += '<div class="fw-finance__invoice-meta-item"><div class="fw-finance__invoice-meta-label">Created By</div><div class="fw-finance__invoice-meta-value">' + esc(createdBy) + '</div></div>';
        headerHtml += '<div class="fw-finance__invoice-meta-item"><div class="fw-finance__invoice-meta-label">Created At</div><div class="fw-finance__invoice-meta-value">' + fmtDate(j.created_at) + '</div></div>';

        if (j.approved_at) {
          headerHtml += '<div class="fw-finance__invoice-meta-item"><div class="fw-finance__invoice-meta-label">Approved At</div><div class="fw-finance__invoice-meta-value">' + fmtDate(j.approved_at) + '</div></div>';
        }
        if (j.posted_at) {
          headerHtml += '<div class="fw-finance__invoice-meta-item"><div class="fw-finance__invoice-meta-label">Posted At</div><div class="fw-finance__invoice-meta-value">' + fmtDate(j.posted_at) + '</div></div>';
        }
        if (j.source_link) {
          headerHtml += '<div class="fw-finance__invoice-meta-item"><div class="fw-finance__invoice-meta-label">Source</div><div class="fw-finance__invoice-meta-value"><a href="' + esc(j.source_link.url) + '">' + esc(j.source_link.label) + '</a></div></div>';
        }
        headerHtml += '</div>';

        // Memo
        if (j.memo) {
          headerHtml += '<div class="fw-finance__invoice-section"><div class="fw-finance__invoice-section-title">Memo</div><div class="fw-finance__invoice-section-body">' + esc(j.memo) + '</div></div>';
        }

        // Reversal notices
        if (j.reversed_by_journal_id) {
          headerHtml += '<div class="fw-finance__alert fw-finance__alert--danger" style="margin-top:var(--fw-spacing-md)">This journal was <strong>reversed</strong> by <a href="/finances/gl/journal_view.php?id=' + j.reversed_by_journal_id + '">Journal #' + j.reversed_by_journal_id + '</a></div>';
        }
        if (j.reverses_journal_id) {
          headerHtml += '<div class="fw-finance__alert fw-finance__alert--info" style="margin-top:var(--fw-spacing-md)">This journal is a <strong>reversal</strong> of <a href="/finances/gl/journal_view.php?id=' + j.reverses_journal_id + '">Journal #' + j.reverses_journal_id + '</a></div>';
        }

        // Lines table
        var totalDebits = 0;
        var totalCredits = 0;
        var linesHtml = lines.map(function(line) {
          var debit = parseFloat(line.debit) || 0;
          var credit = parseFloat(line.credit) || 0;
          totalDebits += debit;
          totalCredits += credit;
          return '<tr>' +
            '<td><strong>' + esc(line.account_code) + '</strong>' + (line.account_name ? ' — ' + esc(line.account_name) : '') + '</td>' +
            '<td>' + (esc(line.description) || '-') + '</td>' +
            '<td class="num">' + (debit > 0 ? 'R ' + fmtCurrency(debit) : '') + '</td>' +
            '<td class="num">' + (credit > 0 ? 'R ' + fmtCurrency(credit) : '') + '</td>' +
          '</tr>';
        }).join('');

        var tableHtml = '<table class="fw-finance__invoice-table" style="margin-top:var(--fw-spacing-xl)">' +
          '<thead><tr>' +
            '<th>Account</th>' +
            '<th>Description</th>' +
            '<th class="num">Debit</th>' +
            '<th class="num">Credit</th>' +
          '</tr></thead>' +
          '<tbody>' + linesHtml + '</tbody>' +
          '<tfoot><tr style="font-weight:700;border-top:2px solid var(--fw-border-base)">' +
            '<td colspan="2" style="text-align:right">Totals</td>' +
            '<td class="num">R ' + fmtCurrency(totalDebits) + '</td>' +
            '<td class="num">R ' + fmtCurrency(totalCredits) + '</td>' +
          '</tr></tfoot>' +
        '</table>';

        container.innerHTML = headerHtml + tableHtml;

      } catch (e) {
        console.error('Journal load failed:', e);
        container.innerHTML = '<div class="fw-finance__empty-state">Failed to load journal entry. Please try again.</div>';
      }
    })();
    </script>
</body>
</html>
