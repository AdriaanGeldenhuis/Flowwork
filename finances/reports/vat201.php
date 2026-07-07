<?php
// /finances/reports/vat201.php
//
// SARS VAT201 form display. Maps VatCalculator output to the official SARS
// VAT201 field numbering (1, 2, 3, 4, 12, 13 output; 14, 15, 16, 19 input;
// 20 net) for review, print, and CSV export. Fields whose data points are
// not tracked (1A/4A capital goods supplied, 2A exports, 17 bad debts,
// 18 other input adjustments) are omitted rather than faked.

$__fin_root = realpath(__DIR__ . '/../..');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
    $permPath = $__fin_root . '/app/finances/permissions.php';
    if (file_exists($permPath)) require_once $permPath;
} else {
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
    $permPath = $__fin_root . '/finances/permissions.php';
    if (file_exists($permPath)) require_once $permPath;
}

require_once __DIR__ . '/../lib/AccountsMap.php';
require_once __DIR__ . '/../lib/VatCalculator.php';

requireRoles(['admin', 'bookkeeper', 'viewer']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) { header('Location: /login.php'); exit; }

// Load company info (tax_reference may not exist if migration not yet applied)
try {
    $stmt = $DB->prepare("SELECT name, vat_number, reg_number, IFNULL(tax_reference, '') AS tax_reference FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stmt = $DB->prepare("SELECT name, vat_number, reg_number FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Load VAT periods for dropdown
$stmt = $DB->prepare(
    "SELECT id, period_start, period_end, status FROM gl_vat_periods WHERE company_id = ? ORDER BY period_start DESC"
);
$stmt->execute([$companyId]);
$periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VAT201 Return – <?= htmlspecialchars($company['name'] ?? 'Company') ?></title>
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <style>
        h1 { margin-bottom: 0.3rem; }
        .subtitle { color: #6c757d; margin-bottom: 1.5rem; }
        .controls { display: flex; gap: 1rem; align-items: end; margin-bottom: 2rem; flex-wrap: wrap; }
        .controls .form-group { display: flex; flex-direction: column; }
        .controls label { font-weight: bold; margin-bottom: 0.3rem; font-size: 0.85rem; }
        .controls select { padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; min-width: 250px; }
        .controls button { padding: 0.5rem 1.2rem; background-color: #38ff12; color: #000; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; height: fit-content; }
        .controls button:disabled { background-color: #aaa; }
        .export-btn { background-color: #0d6efd !important; color: #fff !important; }

        .vat201 { max-width: 800px; background: #fff; border: 2px solid #333; padding: 0; margin-bottom: 2rem; display: none; }
        .vat201-header { background: #1a3a5c; color: #fff; padding: 1.5rem; text-align: center; }
        .vat201-header h2 { margin: 0; font-size: 1.4rem; }
        .vat201-header .form-title { font-size: 1rem; margin-top: 0.3rem; opacity: 0.9; }

        .vat201-info { display: grid; grid-template-columns: 1fr 1fr; gap: 0; border-bottom: 2px solid #333; }
        .vat201-info div { padding: 0.6rem 1rem; border-bottom: 1px solid #ddd; font-size: 0.85rem; }
        .vat201-info .label { font-weight: bold; background: #f8f9fa; }

        .vat201-section { border-bottom: 2px solid #333; }
        .vat201-section-title { background: #e9ecef; padding: 0.5rem 1rem; font-weight: bold; font-size: 0.9rem; border-bottom: 1px solid #ccc; }

        .vat201-row { display: grid; grid-template-columns: 80px 1fr 180px; border-bottom: 1px solid #ddd; }
        .vat201-row:last-child { border-bottom: none; }
        .vat201-row .box-num { padding: 0.5rem; text-align: center; font-weight: bold; background: #f8f9fa; border-right: 1px solid #ddd; font-size: 0.85rem; }
        .vat201-row .box-desc { padding: 0.5rem 0.8rem; font-size: 0.85rem; }
        .vat201-row .box-val { padding: 0.5rem 0.8rem; text-align: right; font-family: monospace; font-size: 0.9rem; border-left: 1px solid #ddd; }

        .vat201-total { background: #fff3cd; }
        .vat201-total .box-val { font-weight: bold; }
        .vat201-grand-total { background: #d4edda; }
        .vat201-grand-total .box-val { font-weight: bold; font-size: 1rem; }
        .vat201-refund { background: #f8d7da; }

        .vat201-footer { padding: 1rem; font-size: 0.8rem; color: #6c757d; border-top: 2px solid #333; }

        .message { margin-top: 1rem; padding: 0.75rem; border-radius: 4px; display: none; max-width: 800px; }
        .message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; display: block; }

        @media print {
            body { padding: 0; background: #fff; }
            a.back, .controls, .export-btn, .message { display: none !important; }
            .vat201 { border: 1px solid #000; display: block !important; }
        }
    </style>
</head>
<body class="fw-finance">
    <div class="fw-finance__container">
    <?php $finTitle = 'VAT201 Return'; $finBack = '/finances/vat.php'; include __DIR__ . '/../partials/header.php'; ?>
    <main class="fw-finance__main">
    <div class="fw-finance__paper">
    <h1>SARS VAT201 Return</h1>
    <p class="subtitle">Value-Added Tax return form with official box mapping</p>

    <div class="controls">
        <div class="form-group">
            <label for="periodSelect">VAT Period</label>
            <select id="periodSelect">
                <option value="">-- Select VAT period --</option>
                <?php foreach ($periods as $p): ?>
                <option value="<?= (int)$p['id'] ?>"
                    data-start="<?= htmlspecialchars($p['period_start']) ?>"
                    data-end="<?= htmlspecialchars($p['period_end']) ?>"
                    data-status="<?= htmlspecialchars($p['status']) ?>">
                    <?= htmlspecialchars($p['period_start'] . ' to ' . $p['period_end']) ?>
                    (<?= htmlspecialchars(ucfirst($p['status'])) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button id="loadBtn" onclick="loadVAT201()">Load VAT201</button>
        <button class="export-btn" id="exportCsvBtn" onclick="exportCSV()" style="display:none;">Export CSV</button>
        <button class="export-btn" id="printBtn" onclick="window.print()" style="display:none;">Print</button>
    </div>

    <div class="message" id="msg"></div>

    <div class="vat201" id="vat201Form">
        <div class="vat201-header">
            <h2>SOUTH AFRICAN REVENUE SERVICE</h2>
            <div class="form-title">VALUE-ADDED TAX RETURN (VAT201)</div>
        </div>

        <div class="vat201-info">
            <div class="label">Vendor Name</div>
            <div id="v_name"><?= htmlspecialchars($company['name'] ?? '') ?></div>
            <div class="label">VAT Registration No.</div>
            <div id="v_vatnum"><?= htmlspecialchars($company['vat_number'] ?? 'Not configured') ?></div>
            <div class="label">Tax Period</div>
            <div id="v_period">-</div>
            <div class="label">Tax Reference No.</div>
            <div id="v_taxref"><?= htmlspecialchars($company['tax_reference'] ?? 'Not configured') ?></div>
        </div>

        <!-- OUTPUT SECTION -->
        <div class="vat201-section">
            <div class="vat201-section-title">CALCULATION OF OUTPUT TAX (SUPPLIES MADE)</div>

            <div class="vat201-row">
                <div class="box-num">Field 1</div>
                <div class="box-desc">Standard rated supplies (excl. capital goods, excl. VAT)</div>
                <div class="box-val" id="field1">R 0.00</div>
            </div>
            <div class="vat201-row">
                <div class="box-num">Field 2</div>
                <div class="box-desc">Zero-rated supplies (incl. exported)</div>
                <div class="box-val" id="field2">R 0.00</div>
            </div>
            <div class="vat201-row">
                <div class="box-num">Field 3</div>
                <div class="box-desc">Exempt supplies and non-supplies</div>
                <div class="box-val" id="field3">R 0.00</div>
            </div>
            <div class="vat201-row">
                <div class="box-num">Field 4</div>
                <div class="box-desc">Output tax on standard rated supplies (Field 1)</div>
                <div class="box-val" id="field4">R 0.00</div>
            </div>
            <div class="vat201-row">
                <div class="box-num">Field 12</div>
                <div class="box-desc">Other and change-in-use output tax adjustments</div>
                <div class="box-val" id="field12">R 0.00</div>
            </div>
            <div class="vat201-row vat201-total">
                <div class="box-num">Field 13</div>
                <div class="box-desc">TOTAL OUTPUT TAX (Field 4 + Field 12)</div>
                <div class="box-val" id="field13">R 0.00</div>
            </div>
        </div>

        <!-- INPUT SECTION -->
        <div class="vat201-section">
            <div class="vat201-section-title">CALCULATION OF INPUT TAX (SUPPLIES RECEIVED)</div>

            <div class="vat201-row">
                <div class="box-num">Field 14</div>
                <div class="box-desc">Input tax on capital goods and/or services</div>
                <div class="box-val" id="field14">R 0.00</div>
            </div>
            <div class="vat201-row">
                <div class="box-num">Field 15</div>
                <div class="box-desc">Input tax on other goods and/or services (not capital goods)</div>
                <div class="box-val" id="field15">R 0.00</div>
            </div>
            <div class="vat201-row">
                <div class="box-num">Field 16</div>
                <div class="box-desc">Input tax adjustments: change in use</div>
                <div class="box-val" id="field16">R 0.00</div>
            </div>
            <div class="vat201-row vat201-total">
                <div class="box-num">Field 19</div>
                <div class="box-desc">TOTAL INPUT TAX (Field 14 + Field 15 + Field 16)</div>
                <div class="box-val" id="field19">R 0.00</div>
            </div>
        </div>

        <!-- NET VAT -->
        <div class="vat201-section">
            <div class="vat201-section-title">NET VAT PAYABLE / REFUNDABLE</div>
            <div class="vat201-row vat201-grand-total" id="field20row">
                <div class="box-num">Field 20</div>
                <div class="box-desc">Net VAT (Field 13 − Field 19): payable if positive, refundable if negative</div>
                <div class="box-val" id="field20">R 0.00</div>
            </div>
        </div>

        <div class="vat201-footer">
            Generated by Flowwork Finance on <span id="genDate"></span>.
            This is a working document for review purposes. The official return must be submitted via SARS eFiling.
        </div>
    </div>
    </div><!-- /fw-finance__paper -->
    </main>
    <footer class="fw-finance__footer">
        <span>VAT201 Return v<?= ASSET_VERSION ?></span>
        <span id="themeIndicator">Theme: Light</span>
    </footer>
    </div><!-- /fw-finance__container -->

<script>
let vatData = null;

function fmt(cents) {
    const val = (cents / 100).toFixed(2);
    return 'R ' + parseFloat(val).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function showMsg(text) {
    const el = document.getElementById('msg');
    el.textContent = text;
    el.className = 'message error';
}

async function loadVAT201() {
    const sel = document.getElementById('periodSelect');
    const opt = sel.options[sel.selectedIndex];
    if (!sel.value) { showMsg('Please select a VAT period.'); return; }

    const periodStart = opt.dataset.start;
    const periodEnd = opt.dataset.end;

    document.getElementById('loadBtn').disabled = true;
    document.getElementById('loadBtn').textContent = 'Loading...';
    document.getElementById('msg').className = 'message';

    try {
        const res = await fetch('/finances/ajax/vat201_data.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ period_id: parseInt(sel.value) })
        });
        const data = await res.json();
        if (!data.ok) { showMsg(data.error || 'Failed to load data'); return; }

        vatData = data.data;

        // Map to official SARS VAT201 fields
        document.getElementById('field1').textContent = fmt(vatData.output_standard_base_cents);
        document.getElementById('field2').textContent = fmt(vatData.output_zero_base_cents);
        document.getElementById('field3').textContent = fmt(vatData.output_exempt_base_cents);
        document.getElementById('field4').textContent = fmt(vatData.output_standard_vat_cents);
        document.getElementById('field12').textContent = fmt(vatData.change_in_use_output_cents || 0);
        document.getElementById('field13').textContent = fmt(vatData.total_output_vat_cents);
        document.getElementById('field14').textContent = fmt(vatData.input_capital_cents);
        document.getElementById('field15').textContent = fmt(vatData.input_other_cents);
        document.getElementById('field16').textContent = fmt(vatData.change_in_use_input_cents || 0);
        document.getElementById('field19').textContent = fmt(vatData.total_input_vat_cents);
        document.getElementById('field20').textContent = fmt(vatData.net_vat_cents);

        // Style field 20 based on payable/refundable
        const row = document.getElementById('field20row');
        row.className = 'vat201-row ' + (vatData.net_vat_cents >= 0 ? 'vat201-grand-total' : 'vat201-refund');

        // Period info
        document.getElementById('v_period').textContent = periodStart + ' to ' + periodEnd;
        document.getElementById('genDate').textContent = new Date().toISOString().slice(0, 10);

        document.getElementById('vat201Form').style.display = 'block';
        document.getElementById('exportCsvBtn').style.display = 'inline-block';
        document.getElementById('printBtn').style.display = 'inline-block';
    } catch (err) {
        showMsg('Network error: ' + err.message);
    } finally {
        document.getElementById('loadBtn').disabled = false;
        document.getElementById('loadBtn').textContent = 'Load VAT201';
    }
}

function exportCSV() {
    if (!vatData) return;
    const rows = [
        ['Field', 'Description', 'Amount (ZAR)'],
        ['1', 'Standard rated supplies (excl. capital goods, excl. VAT)', (vatData.output_standard_base_cents / 100).toFixed(2)],
        ['2', 'Zero-rated supplies (incl. exported)', (vatData.output_zero_base_cents / 100).toFixed(2)],
        ['3', 'Exempt supplies and non-supplies', (vatData.output_exempt_base_cents / 100).toFixed(2)],
        ['4', 'Output tax on standard rated supplies (Field 1)', (vatData.output_standard_vat_cents / 100).toFixed(2)],
        ['12', 'Other and change-in-use output tax adjustments', ((vatData.change_in_use_output_cents || 0) / 100).toFixed(2)],
        ['13', 'TOTAL OUTPUT TAX (Field 4 + Field 12)', (vatData.total_output_vat_cents / 100).toFixed(2)],
        ['14', 'Input tax on capital goods and/or services', (vatData.input_capital_cents / 100).toFixed(2)],
        ['15', 'Input tax on other goods and/or services (not capital goods)', (vatData.input_other_cents / 100).toFixed(2)],
        ['16', 'Input tax adjustments: change in use', ((vatData.change_in_use_input_cents || 0) / 100).toFixed(2)],
        ['19', 'TOTAL INPUT TAX (Field 14 + Field 15 + Field 16)', (vatData.total_input_vat_cents / 100).toFixed(2)],
        ['20', 'Net VAT payable / refundable (Field 13 - Field 19)', (vatData.net_vat_cents / 100).toFixed(2)]
    ];
    const csv = rows.map(r => r.map(c => '"' + c + '"').join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'VAT201_' + document.getElementById('v_period').textContent.replace(/\s/g, '_') + '.csv';
    a.click();
}
</script>
<script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
