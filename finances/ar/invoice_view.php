<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
require_once __DIR__ . '/../init.php';
requireRoles(['viewer','bookkeeper','admin']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$title = 'Invoice #' . $id;
include __DIR__ . '/../partials/header.php';
?>
<div class="container">
  <h1>Invoice <span id="invNo"></span></h1>
  <div id="meta"></div>
  <table id="lines" class="table">
    <thead>
      <tr><th>Description</th><th>Qty</th><th>Unit</th><th>Unit Price</th><th>Tax %</th><th>Line Total</th></tr>
    </thead>
    <tbody></tbody>
    <tfoot>
      <tr><td colspan="5" style="text-align:right">Subtotal</td><td id="subtotal" style="text-align:right"></td></tr>
      <tr><td colspan="5" style="text-align:right">Discount</td><td id="discount" style="text-align:right"></td></tr>
      <tr><td colspan="5" style="text-align:right">Tax</td><td id="tax" style="text-align:right"></td></tr>
      <tr><td colspan="5" style="text-align:right"><strong>Total</strong></td><td id="total" style="text-align:right"></td></tr>
      <tr><td colspan="5" style="text-align:right">Balance due</td><td id="balance" style="text-align:right"></td></tr>
    </tfoot>
  </table>
  <p>
    <a id="qiLink" href="#" target="_blank">Open in Q&I</a>
    <span id="journal"></span>
  </p>
</div>
<script>
(async function(){
  const res = await fetch('../ajax/ar_invoice_view.php?id=<?=$id?>', {headers:{'Accept':'application/json'}});
  const js = await res.json();
  if(!js.ok){ alert(js.error||'Error'); return; }
  const d = js.data;
  document.title = 'Invoice ' + (d.invoice_number||'') + ' · Finances';
  document.getElementById('invNo').textContent = d.invoice_number||'';
  const m = document.getElementById('meta');
  function esc(s){ return String(s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
  function fmt(n){ try { return new Intl.NumberFormat(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}).format(Number(n||0)); } catch(e){ return (n||0); } }
  m.innerHTML = `
    <div><strong>Customer:</strong> ${esc(d.customer_name||'')}</div>
    <div><strong>Status:</strong> ${esc(d.status||'')}</div>
    <div><strong>Issue date:</strong> ${esc(d.issue_date||'')}</div>
    <div><strong>Due date:</strong> ${esc(d.due_date||'')}</div>
  `;
  const tbody = document.querySelector('#lines tbody');
  tbody.innerHTML = (d.lines||[]).map(l => `
    <tr>
      <td>${esc(l.item_description)}</td>
      <td style="text-align:right">${esc(l.quantity)}</td>
      <td>${esc(l.unit||'')}</td>
      <td style="text-align:right">${fmt(l.unit_price)}</td>
      <td style="text-align:right">${fmt(l.tax_rate)}</td>
      <td style="text-align:right">${fmt(l.line_total)}</td>
    </tr>
  `).join('');
  document.getElementById('subtotal').textContent = fmt(d.subtotal);
  document.getElementById('discount').textContent = fmt(d.discount);
  document.getElementById('tax').textContent = fmt(d.tax);
  document.getElementById('total').textContent = fmt(d.total);
  document.getElementById('balance').textContent = fmt(d.balance_due);
  document.getElementById('qiLink').href = '/qi/invoice_view.php?id=' + encodeURIComponent(d.id);
  if (d.journal_id){
    document.getElementById('journal').innerHTML = ' · <a href="/finances/gl/journal_view.php?id=' + encodeURIComponent(d.journal_id) + '">Journal #' + d.journal_id + '</a>';
  }
})();
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
