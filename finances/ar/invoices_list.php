<?php
require_once __DIR__ . '/../init.php';
requireRoles(['viewer','bookkeeper','admin']);

// Minimal server-rendered shell with a data table fed by AJAX.
// Keep styling to existing global CSS; do NOT add new assets.
$title = 'AR Invoices';
include __DIR__ . '/../partials/header.php'; // if your app uses a standard header
?>
<div class="container">
  <h1>Accounts Receivable — Invoices</h1>
  <form id="filters" class="filters">
    <input type="text" name="q" placeholder="Search invoice no or customer" />
    <select name="status">
      <option value="">Any status</option>
      <option value="draft">Draft</option>
      <option value="sent">Sent</option>
      <option value="viewed">Viewed</option>
      <option value="paid">Paid</option>
      <option value="overdue">Overdue</option>
      <option value="cancelled">Cancelled</option>
    </select>
    <input type="date" name="date_from" />
    <input type="date" name="date_to" />
    <button type="submit">Apply</button>
  </form>

  <table id="tbl" class="table">
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

  <div id="pager">
    <button id="prev">Prev</button>
    <span id="pageLabel"></span>
    <button id="next">Next</button>
  </div>
</div>

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
<?php include __DIR__ . '/../partials/footer.php'; ?>
