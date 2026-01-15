<?php
// /finances/partials/overview-tables.php
// Contains the skeleton for the worklist tables used in the finance overview.
?>
<div class="fw-finance-overview__row fw-finance-overview__worklists">
    <div class="fw-finance-overview__worklist">
        <h3 class="fw-finance-overview__worklist-title">Invoices Due (Next 7 Days)</h3>
        <table id="tbl-due" class="fw-finance-overview__table"></table>
    </div>
    <div class="fw-finance-overview__worklist">
        <h3 class="fw-finance-overview__worklist-title">Overdue Invoices</h3>
        <table id="tbl-overdue" class="fw-finance-overview__table"></table>
    </div>
    <div class="fw-finance-overview__worklist">
        <h3 class="fw-finance-overview__worklist-title">Recurring Invoices (Next 14 Days)</h3>
        <table id="tbl-recurring" class="fw-finance-overview__table"></table>
    </div>
    <div class="fw-finance-overview__worklist">
        <h3 class="fw-finance-overview__worklist-title">Receipts (Parsed Not Posted)</h3>
        <table id="tbl-receipts" class="fw-finance-overview__table"></table>
    </div>
</div>