<?php
require_once __DIR__ . '/../permissions.php';
requireRoles(['viewer','bookkeeper','admin']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

// Minimal server-rendered shell with a data table fed by AJAX.
$title = 'AR Invoices';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($title) ?> – Finances</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-finance">
<div class="fw-finance__container">
  <?php
  $finTitle = 'Invoices';
  $finBack = '/finances/ar/';
  include __DIR__ . '/../partials/header.php';
  ?>
  <main class="fw-finance__main">
    <h1>Accounts Receivable — Invoices</h1>
    <form id="filters" class="fw-finance__toolbar">
      <input type="text" name="q" class="fw-finance__search" placeholder="Search invoice no or customer" />
      <select name="status" class="fw-finance__filter">
        <option value="">Any status</option>
        <option value="draft">Draft</option>
        <option value="sent">Sent</option>
        <option value="viewed">Viewed</option>
        <option value="paid">Paid</option>
        <option value="overdue">Overdue</option>
        <option value="cancelled">Cancelled</option>
      </select>
      <input type="date" name="date_from" class="fw-finance__filter" />
      <input type="date" name="date_to" class="fw-finance__filter" />
      <button type="submit" class="fw-finance__btn fw-finance__btn--primary">Apply</button>
    </form>

    <table id="tbl" class="fw-finance__table">
      <thead>
        <tr>
          <th>#</th>
          <th>Issue date</th>
          <th>Customer</th>
          <th>Status</th>
          <th>Total</th>
          <th>Balance</th>
          <th></th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>

    <div id="pager" class="fw-finance__pagination">
      <button id="prev" class="fw-finance__btn fw-finance__btn--secondary fw-finance__btn--small">Prev</button>
      <span id="pageLabel" class="fw-finance__pagination-info"></span>
      <button id="next" class="fw-finance__btn fw-finance__btn--secondary fw-finance__btn--small">Next</button>
    </div>
  </main>
  <footer class="fw-finance__footer">
    <span>Invoices v<?= ASSET_VERSION ?></span>
    <span id="themeIndicator">Theme: Light</span>
  </footer>
</div>

<script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
<script>
(function(){
  const tbody = document.querySelector('#tbl tbody');
  const form = document.querySelector('#filters');
  const pageLabel = document.querySelector('#pageLabel');
  let page = 1, limit = 25, lastCount = 0;

  function fmt(n){
    try { return new Intl.NumberFormat(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}).format(Number(n||0)); } catch(e){ return (n||0); }
  }
  function esc(s){ return String(s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

  async function load(){
    const params = new URLSearchParams(new FormData(form));
    params.set('page', page);
    params.set('limit', limit);
    const res = await fetch('../ajax/ar_invoices_list.php?' + params.toString(), {headers:{'Accept':'application/json'}});
    const js = await res.json();
    if(!js.ok){ alert(js.error||'Error'); return; }
    const rows = js.data.rows || [];
    lastCount = rows.length;
    pageLabel.textContent = 'Page ' + page;
    tbody.innerHTML = rows.map(r => `
      <tr>
        <td>${esc(r.invoice_number)}</td>
        <td>${esc(r.issue_date)}</td>
        <td>${esc(r.customer_name)}</td>
        <td>${esc(r.status)}</td>
        <td style="text-align:right">${fmt(r.total)}</td>
        <td style="text-align:right">${fmt(r.balance_due)}</td>
        <td><a href="invoice_view.php?id=${encodeURIComponent(r.id)}">View</a> · <a href="/qi/invoice_view.php?id=${encodeURIComponent(r.id)}" target="_blank">Open in Q&I</a></td>
      </tr>
    `).join('');
  }

  form.addEventListener('submit', function(ev){ ev.preventDefault(); page = 1; load(); });
  document.querySelector('#prev').addEventListener('click', function(){ if(page>1){ page--; load(); } });
  document.querySelector('#next').addEventListener('click', function(){ if(lastCount===limit){ page++; load(); } });

  load();
})();
</script>
</body>
</html>
