<?php
// /finances/partials/overview-cards.php
// Contains the skeleton for the KPI cards used in the finance overview.
?>
<div class="fw-finance-overview__row fw-finance-overview__kpi-cards">
    <div class="fw-finance-overview__kpi-card" id="kpi-cash">
        <div class="fw-finance-overview__kpi-icon fw-finance-overview__kpi-icon--cash">💰</div>
        <div class="fw-finance-overview__kpi-content">
            <div class="fw-finance-overview__kpi-value" id="kpi-cash-value">R&nbsp;0.00</div>
            <div class="fw-finance-overview__kpi-label">Cash on Hand</div>
        </div>
    </div>
    <div class="fw-finance-overview__kpi-card" id="kpi-ar">
        <div class="fw-finance-overview__kpi-icon fw-finance-overview__kpi-icon--ar">📬</div>
        <div class="fw-finance-overview__kpi-content">
            <div class="fw-finance-overview__kpi-value" id="kpi-ar-value">R&nbsp;0.00</div>
            <div class="fw-finance-overview__kpi-label">
                AR Open <span class="fw-finance-overview__badge" id="kpi-ar-overdue"></span>
            </div>
        </div>
    </div>
    <div class="fw-finance-overview__kpi-card" id="kpi-sales">
        <div class="fw-finance-overview__kpi-icon fw-finance-overview__kpi-icon--sales">📈</div>
        <div class="fw-finance-overview__kpi-content">
            <div class="fw-finance-overview__kpi-value" id="kpi-sales-value">R&nbsp;0.00</div>
            <div class="fw-finance-overview__kpi-label">Sales (Period)</div>
        </div>
    </div>
    <div class="fw-finance-overview__kpi-card" id="kpi-ap">
        <div class="fw-finance-overview__kpi-icon fw-finance-overview__kpi-icon--ap">📥</div>
        <div class="fw-finance-overview__kpi-content">
            <div class="fw-finance-overview__kpi-value" id="kpi-ap-value">R&nbsp;0.00</div>
            <div class="fw-finance-overview__kpi-label">AP Captured (Unposted)</div>
        </div>
    </div>
</div>