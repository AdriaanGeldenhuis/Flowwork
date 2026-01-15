<?php
/**
 * Simple Email Log Modal
 * Drop this include into quote_view.php / invoice_view.php and add a button:
 *   <button class="btn" data-email-log data-entity-type="invoice" data-entity-id="<?= (int)$invoice['id'] ?>">Email Log</button>
 */
?>
<div id="emailLogModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); align-items:center; justify-content:center; z-index:9999;">
  <div style="background:#fff; color:#111; min-width:480px; max-width:800px; border-radius:12px; overflow:hidden;">
    <div style="padding:14px 16px; background:#06b6d4; color:#fff; font-weight:600;">Email Log</div>
    <div id="emailLogBody" style="padding:16px; max-height:60vh; overflow:auto; font-family:system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;"></div>
    <div style="display:flex; justify-content:flex-end; gap:8px; padding:12px 16px;">
      <button id="emailLogClose" style="padding:8px 12px; border-radius:8px; border:none; background:#111; color:#fff; cursor:pointer;">Close</button>
    </div>
  </div>
</div>
