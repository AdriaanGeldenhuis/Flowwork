<?php
// /finances/reports/vat201.php
//
// SARS VAT201 form display. Maps VatCalculator output to official SARS
// VAT201 boxes (1-10) for review, print, and CSV export.

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

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) { header('Location: /login.php'); exit; }

// Load company info
$stmt = $DB->prepare("SELECT name, vat_number, reg_number, IFNULL(tax_reference, '') AS tax_reference FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);

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
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 2rem; background-color: #f8f9fa; }
        a.back { display: inline-block; margin-bottom: 1rem; color: #0d6efd; text-decoration: none; }
        a.back:hover { text-decoration: underline; }
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
<body>
    <a class="back" href="/finances/vat.php">&larr; Back to VAT Returns</a>
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
            <div class="vat201-section-title">OUTPUT TAX (SUPPLIES MADE)</div>

            <div class="vat201-row">
                <div class="box-num">Box 1</div>
                <div class="box-desc">Standard rated supplies (excl. VAT)</div>
                <div class="box-val" id="box1">R 0.00</div>
            </div>
            <div class="vat201-row">
                <div class="box-num">Box 1A</div>
                <div class="box-desc">Output VAT on standard rated supplies</div>
                <div class="box-val" id="box1a">R 0.00</div>
            </div>
            <div class="vat201-row">
                <div class="box-num">Box 2</div>
                <div class="box-desc">Zero-rated supplies</div>
                <div class="box-val" id="box2">R 0.00</div>
            </div>
            <div class="vat201-row">
                <div class="box-num">Box 3</div>
                <div class="box-desc">Exempt supplies</div>
                <div class="box-val" id="box3">R 0.00</div>
            </div>
            <div class="vat201-row">
                <div class="box-num">Box 4</div>
                <div class="box-desc">Other output tax adjustments</div>
                <div class="box-val" id="box4">R 0.00</div>
            </div>
            <div class="vat201-row vat201-total">
                <div class="box-num">Box 5</div>
                <div class="box-desc">Total output tax (Box 1A + Box 4)</div>
                <div class="box-val" id="box5">R 0.00</div>
            </div>
        </div>

        <!-- INPUT SECTION -->
        <div class="vat201-section">
            <div class="vat201-section-title">INPUT TAX (ACQUISITIONS)</div>

            <div class="vat201-row">
                <div class="box-num">Box 6</div>
                <div class="box-desc">Change in use adjustments</div>
                <div class="box-val" id="box6">R 0.00</div>
            </div>
            <div class="vat201-row">
                <div class="box-num">Box 7</div>
                <div class="box-desc">Input tax on capital goods</div>
                <div class="box-val" id="box7">R 0.00</div>
            </div>
            <div class="vat201-row">
                <div class="box-num">Box 8</div>
                <div class="box-desc">Other input tax (non-capital)</div>
                <div class="box-val" id="box8">R 0.00</div>
            </div>
            <div class="vat201-row vat201-total">
                <div class="box-num">Box 9</div>
                <div class="box-desc">Total input tax (Box 6 + Box 7 + Box 8)</div>
                <div class="box-val" id="box9">R 0.00</div>
            </div>
        </div>

        <!-- NET VAT -->
        <div class="vat201-section">
            <div class="vat201-section-title">TAX PAYABLE / REFUNDABLE</div>
            <div class="vat201-row vat201-grand-total" id="box10row">
                <div class="box-num">Box 10</div>
                <div class="box-desc">VAT payable (positive) or refundable (negative)</div>
                <div class="box-val" id="box10">R 0.00</div>
            </div>
        </div>

        <div class="vat201-footer">
            Generated by Flowwork Finance on <span id="genDate"></span>.
            This is a working document for review purposes. The official return must be submitted via SARS eFiling.
        </div>
    </div>

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

        // Map to SARS boxes
        document.getElementById('box1').textContent = fmt(vatData.output_standard_base_cents);
        document.getElementById('box1a').textContent = fmt(vatData.output_standard_vat_cents);
        document.getElementById('box2').textContent = fmt(vatData.output_zero_base_cents);
        document.getElementById('box3').textContent = fmt(vatData.output_exempt_base_cents);
        document.getElementById('box4').textContent = fmt(vatData.change_in_use_output_cents || 0);
        document.getElementById('box5').textContent = fmt(vatData.total_output_vat_cents);
        document.getElementById('box6').textContent = fmt(vatData.change_in_use_input_cents || 0);
        document.getElementById('box7').textContent = fmt(vatData.input_capital_cents);
        document.getElementById('box8').textContent = fmt(vatData.input_other_cents);
        document.getElementById('box9').textContent = fmt(vatData.total_input_vat_cents);
        document.getElementById('box10').textContent = fmt(vatData.net_vat_cents);

        // Style box 10 based on payable/refundable
        const row = document.getElementById('box10row');
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
        ['Box', 'Description', 'Amount (ZAR)'],
        ['1', 'Standard rated supplies (excl. VAT)', (vatData.output_standard_base_cents / 100).toFixed(2)],
        ['1A', 'Output VAT on standard rated supplies', (vatData.output_standard_vat_cents / 100).toFixed(2)],
        ['2', 'Zero-rated supplies', (vatData.output_zero_base_cents / 100).toFixed(2)],
        ['3', 'Exempt supplies', (vatData.output_exempt_base_cents / 100).toFixed(2)],
        ['4', 'Other output tax adjustments', ((vatData.change_in_use_output_cents || 0) / 100).toFixed(2)],
        ['5', 'Total output tax', (vatData.total_output_vat_cents / 100).toFixed(2)],
        ['6', 'Change in use adjustments', ((vatData.change_in_use_input_cents || 0) / 100).toFixed(2)],
        ['7', 'Input tax on capital goods', (vatData.input_capital_cents / 100).toFixed(2)],
        ['8', 'Other input tax', (vatData.input_other_cents / 100).toFixed(2)],
        ['9', 'Total input tax', (vatData.total_input_vat_cents / 100).toFixed(2)],
        ['10', 'VAT payable / refundable', (vatData.net_vat_cents / 100).toFixed(2)]
    ];
    const csv = rows.map(r => r.map(c => '"' + c + '"').join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'VAT201_' + document.getElementById('v_period').textContent.replace(/\s/g, '_') + '.csv';
    a.click();
}
</script>
</body>
</html>
