<?php
// /finances/exchange_rates.php
//
// Exchange rate management page. Allows admin/bookkeeper users to
// maintain exchange rates for foreign currency transactions.
// SARS requires translation at the transaction date rate.

$__fin_root = realpath(__DIR__ . '/..');
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

requireRoles(['admin', 'bookkeeper']);

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) { header('Location: /login.php'); exit; }

// Fetch recent exchange rates
$stmt = $DB->prepare(
    "SELECT id, currency_code, rate_date, rate_to_zar, source, created_at
     FROM gl_exchange_rates
     WHERE company_id = ?
     ORDER BY rate_date DESC, currency_code
     LIMIT 200"
);
$stmt->execute([$companyId]);
$rates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$commonCurrencies = ['USD', 'EUR', 'GBP', 'BWP', 'MZN', 'NAD', 'SZL', 'ZMW', 'KES', 'NGN', 'AUD', 'CNY', 'JPY', 'CHF', 'CAD'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exchange Rates</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 2rem; background-color: #f8f9fa; }
        a.back { display: inline-block; margin-bottom: 1rem; color: #0d6efd; text-decoration: none; }
        a.back:hover { text-decoration: underline; }
        h1 { margin-bottom: 0.3rem; }
        .subtitle { color: #6c757d; margin-bottom: 1.5rem; }
        .card { max-width: 600px; background: #fff; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 2rem; }
        .form-row { display: flex; gap: 0.75rem; align-items: end; flex-wrap: wrap; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-weight: bold; margin-bottom: 0.3rem; font-size: 0.85rem; }
        .form-group input, .form-group select { padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; }
        button { padding: 0.5rem 1.2rem; background-color: #38ff12; color: #000; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
        button:disabled { background-color: #aaa; }
        .message { margin-top: 0.5rem; font-weight: bold; font-size: 0.85rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.85rem; }
        th, td { border: 1px solid #ddd; padding: 0.4rem 0.6rem; text-align: left; }
        th { background-color: #f1f1f1; }
        .num { text-align: right; font-family: monospace; }
        .info-box { background-color: #e7f3ff; border: 1px solid #b6d4fe; padding: 0.75rem; border-radius: 4px; margin-bottom: 1.5rem; font-size: 0.85rem; color: #084298; }
    </style>
</head>
<body>
    <a class="back" href="/finances/">&larr; Back to Finance Dashboard</a>
    <h1>Exchange Rates</h1>
    <p class="subtitle">Manage foreign currency exchange rates (1 unit = X ZAR)</p>

    <div class="info-box">
        <strong>SARS Requirement:</strong> Foreign currency transactions must be translated to ZAR at the exchange rate
        on the date of the transaction (Income Tax Act, Section 25D). Maintain daily rates for all currencies used.
    </div>

    <div class="card">
        <h3 style="margin-top:0;">Add Exchange Rate</h3>
        <form id="rateForm">
            <div class="form-row">
                <div class="form-group">
                    <label>Currency</label>
                    <select id="currency" required>
                        <option value="">Select</option>
                        <?php foreach ($commonCurrencies as $c): ?>
                        <option value="<?= $c ?>"><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Date</label>
                    <input type="date" id="rateDate" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label>Rate to ZAR</label>
                    <input type="number" id="rateValue" step="0.000001" min="0" placeholder="e.g. 18.50" required>
                </div>
                <button type="submit" id="saveBtn">Save</button>
            </div>
            <div class="message" id="formMsg"></div>
        </form>
    </div>

    <h3>Exchange Rate History</h3>
    <table>
        <thead>
            <tr><th>Currency</th><th>Date</th><th>Rate (1 unit = ZAR)</th><th>Source</th><th>Created</th></tr>
        </thead>
        <tbody id="ratesBody">
            <?php foreach ($rates as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['currency_code']) ?></td>
                <td><?= htmlspecialchars($r['rate_date']) ?></td>
                <td class="num"><?= number_format((float)$r['rate_to_zar'], 6) ?></td>
                <td><?= htmlspecialchars($r['source']) ?></td>
                <td><?= htmlspecialchars(substr($r['created_at'], 0, 16)) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rates)): ?>
            <tr><td colspan="5" style="text-align:center; color:#6c757d;">No exchange rates configured yet</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

<script>
document.getElementById('rateForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const currency = document.getElementById('currency').value;
    const date = document.getElementById('rateDate').value;
    const rate = parseFloat(document.getElementById('rateValue').value);
    const msg = document.getElementById('formMsg');
    const btn = document.getElementById('saveBtn');

    if (!currency || !date || !rate) { msg.textContent = 'All fields required'; return; }

    btn.disabled = true;
    btn.textContent = 'Saving...';
    msg.textContent = '';

    try {
        const res = await fetch('/finances/ajax/exchange_rate_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ currency_code: currency, rate_date: date, rate_to_zar: rate })
        });
        const data = await res.json();
        if (data.ok) {
            msg.textContent = 'Rate saved!';
            msg.style.color = '#0a7d32';
            // Add row to table
            const tbody = document.getElementById('ratesBody');
            const tr = document.createElement('tr');
            tr.innerHTML = '<td>' + currency + '</td><td>' + date + '</td><td class="num">' +
                rate.toFixed(6) + '</td><td>manual</td><td>' + new Date().toISOString().slice(0,16).replace('T',' ') + '</td>';
            tbody.insertBefore(tr, tbody.firstChild);
        } else {
            msg.textContent = data.error || 'Failed';
            msg.style.color = '#dc3545';
        }
    } catch (err) {
        msg.textContent = 'Network error';
        msg.style.color = '#dc3545';
    } finally {
        btn.disabled = false;
        btn.textContent = 'Save';
    }
});
</script>
</body>
</html>
