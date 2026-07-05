<?php
// /finances/year_end.php
//
// Year-End Closing page. Allows admin users to close a fiscal year by
// transferring net income (revenue minus expenses) into Retained Earnings.
// This creates a closing journal entry that zeroes out all revenue and
// expense accounts and posts the net result to account 3100 (Retained Earnings).

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

requireRoles(['admin']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

$companyId = $_SESSION['company_id'] ?? null;
$userId    = $_SESSION['user_id'] ?? null;
if (!$companyId || !$userId) {
    header('Location: /login.php');
    exit;
}

// Get fiscal year start setting
$stmt = $DB->prepare("SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = 'finance_fiscal_year_start' LIMIT 1");
$stmt->execute([$companyId]);
$fyMonthName = $stmt->fetchColumn() ?: 'March';
$fyMonthNum = (int)date('n', strtotime($fyMonthName . ' 1'));
if (!$fyMonthNum) $fyMonthNum = 3;

// Build list of closeable fiscal years (current year minus 1, minus 2, etc.)
$currentYear = (int)date('Y');
$currentMonth = (int)date('n');
// If we haven't reached the FY start month this calendar year, the current FY started last year
if ($currentMonth < $fyMonthNum) {
    $latestFYStartYear = $currentYear - 1;
} else {
    $latestFYStartYear = $currentYear;
}

$fiscalYears = [];
for ($i = 0; $i < 5; $i++) {
    $startYear = $latestFYStartYear - $i;
    $endYear = ($fyMonthNum === 1) ? $startYear : $startYear + 1;
    $endMonth = $fyMonthNum - 1;
    if ($endMonth <= 0) { $endMonth = 12; $endYear = $startYear; }

    $startDate = sprintf('%04d-%02d-01', $startYear, $fyMonthNum);
    $endDate = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $endYear, $endMonth)));

    $fiscalYears[] = [
        'label' => $fyMonthName . ' ' . $startYear . ' – ' . date('F', mktime(0,0,0,$endMonth,1)) . ' ' . $endYear,
        'start' => $startDate,
        'end'   => $endDate
    ];
}

// Check which fiscal years already have closing journals
$closedYears = [];
$stmt = $DB->prepare(
    "SELECT reference FROM journal_entries WHERE company_id = ? AND reference LIKE 'YE-CLOSE-%' AND status = 'posted'"
);
$stmt->execute([$companyId]);
while ($ref = $stmt->fetchColumn()) {
    $closedYears[$ref] = true;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Year-End Closing</title>
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <style>
        h1 { margin-bottom: 0.5rem; }
        .subtitle { color: #6c757d; margin-bottom: 2rem; }
        .card { max-width: 700px; background: #fff; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 2rem; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; font-weight: bold; margin-bottom: 0.5rem; }
        select { width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; }
        button { padding: 0.6rem 1.2rem; background-color: #38ff12; color: #000; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
        button:disabled { background-color: #aaa; cursor: not-allowed; }
        .message { margin-top: 1rem; padding: 0.75rem; border-radius: 4px; display: none; }
        .message.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; display: block; }
        .message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; display: block; }
        .info-box { background-color: #e7f3ff; border: 1px solid #b6d4fe; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem; font-size: 0.9rem; color: #084298; }
        .warning-box { background-color: #fff3cd; border: 1px solid #ffecb5; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem; font-size: 0.9rem; color: #664d03; }
        .preview { margin-top: 1rem; }
        .preview table { width: 100%; border-collapse: collapse; margin-top: 0.5rem; font-size: 0.85rem; }
        .preview th, .preview td { border: 1px solid #ddd; padding: 0.4rem 0.6rem; text-align: left; }
        .preview th { background-color: #f1f1f1; }
        .preview .total-row { font-weight: bold; background-color: #f9f9f9; }
        .badge { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 3px; font-size: 0.75rem; font-weight: bold; }
        .badge-closed { background-color: #d4edda; color: #155724; }
        .badge-open { background-color: #fff3cd; color: #664d03; }
    </style>
</head>
<body class="fw-finance">
    <div class="fw-finance__container">
    <?php $finTitle = 'Year-End Closing'; $finBack = '/finances/'; include __DIR__ . '/partials/header.php'; ?>
    <main class="fw-finance__main">
    <div class="fw-finance__paper">
    <h1>Year-End Closing</h1>
    <p class="subtitle">Close a fiscal year by transferring net income to Retained Earnings (3100)</p>

    <div class="card">
        <div class="info-box">
            <strong>What this does:</strong> Creates a closing journal entry that debits all revenue accounts
            and credits all expense accounts, posting the net difference to Retained Earnings.
            This zeroes out P&amp;L accounts so the new fiscal year starts clean.
        </div>

        <div class="warning-box">
            <strong>Important:</strong> Only close a fiscal year after all transactions for that period have been
            posted and reviewed. This action creates a posted journal entry and should be followed by a period lock.
        </div>

        <div class="form-group">
            <label for="fiscal_year">Select Fiscal Year to Close</label>
            <select id="fiscal_year">
                <option value="">-- Select fiscal year --</option>
                <?php foreach ($fiscalYears as $fy):
                    $ref = 'YE-CLOSE-' . substr($fy['start'], 0, 4) . '-' . substr($fy['end'], 0, 4);
                    $isClosed = isset($closedYears[$ref]);
                ?>
                <option value="<?= htmlspecialchars($fy['start']) ?>|<?= htmlspecialchars($fy['end']) ?>"
                    <?= $isClosed ? 'disabled' : '' ?>>
                    <?= htmlspecialchars($fy['label']) ?>
                    <?= $isClosed ? ' (CLOSED)' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button id="previewBtn" onclick="previewClose()">Preview Closing Entry</button>
        <button id="closeBtn" onclick="executeClose()" style="display:none; margin-left: 0.5rem; background-color: #dc3545; color: #fff;">Confirm &amp; Post Closing Journal</button>

        <div class="message" id="msg"></div>

        <div class="preview" id="previewArea" style="display:none;">
            <h3>Closing Journal Preview</h3>
            <table>
                <thead>
                    <tr><th>Account Code</th><th>Account Name</th><th>Debit (ZAR)</th><th>Credit (ZAR)</th></tr>
                </thead>
                <tbody id="previewBody"></tbody>
            </table>
        </div>
    </div>
    </div><!-- /fw-finance__paper -->
    </main>
    <footer class="fw-finance__footer">
        <span>Year-End Closing v<?= ASSET_VERSION ?></span>
        <span id="themeIndicator">Theme: Light</span>
    </footer>
    </div><!-- /fw-finance__container -->

<script>
let previewData = null;

function showMsg(text, type) {
    const el = document.getElementById('msg');
    el.textContent = text;
    el.className = 'message ' + type;
}

function clearMsg() {
    const el = document.getElementById('msg');
    el.className = 'message';
    el.style.display = 'none';
}

function fmt(cents) {
    return 'R ' + (cents / 100).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

async function previewClose() {
    const sel = document.getElementById('fiscal_year').value;
    if (!sel) { showMsg('Please select a fiscal year.', 'error'); return; }
    const [start, end] = sel.split('|');
    clearMsg();
    document.getElementById('previewBtn').disabled = true;
    document.getElementById('previewBtn').textContent = 'Loading...';
    document.getElementById('previewArea').style.display = 'none';
    document.getElementById('closeBtn').style.display = 'none';

    try {
        const res = await fetch('/finances/ajax/year_end_close.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'preview', fy_start: start, fy_end: end })
        });
        const data = await res.json();
        if (!data.ok) { showMsg(data.error || 'Preview failed', 'error'); return; }

        previewData = data;
        const tbody = document.getElementById('previewBody');
        tbody.innerHTML = '';
        let totalDebit = 0, totalCredit = 0;
        data.lines.forEach(line => {
            const tr = document.createElement('tr');
            const debit = line.debit_cents || 0;
            const credit = line.credit_cents || 0;
            totalDebit += debit;
            totalCredit += credit;
            tr.innerHTML = '<td>' + line.account_code + '</td><td>' + line.account_name +
                '</td><td>' + (debit ? fmt(debit) : '') + '</td><td>' + (credit ? fmt(credit) : '') + '</td>';
            tbody.appendChild(tr);
        });
        // Total row
        const totalTr = document.createElement('tr');
        totalTr.className = 'total-row';
        totalTr.innerHTML = '<td colspan="2"><strong>Total</strong></td><td>' + fmt(totalDebit) + '</td><td>' + fmt(totalCredit) + '</td>';
        tbody.appendChild(totalTr);

        document.getElementById('previewArea').style.display = 'block';

        if (data.lines.length > 1) {
            document.getElementById('closeBtn').style.display = 'inline-block';
            showMsg('Preview generated. Review the entries below, then click "Confirm & Post Closing Journal".', 'success');
        } else {
            showMsg('No revenue or expense balances found for this period. Nothing to close.', 'error');
        }
    } catch (err) {
        showMsg('Network error: ' + err.message, 'error');
    } finally {
        document.getElementById('previewBtn').disabled = false;
        document.getElementById('previewBtn').textContent = 'Preview Closing Entry';
    }
}

async function executeClose() {
    if (!previewData) return;
    if (!confirm('Are you sure you want to post the year-end closing journal? This cannot be undone easily.')) return;

    const sel = document.getElementById('fiscal_year').value;
    const [start, end] = sel.split('|');
    document.getElementById('closeBtn').disabled = true;
    document.getElementById('closeBtn').textContent = 'Posting...';

    try {
        const res = await fetch('/finances/ajax/year_end_close.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'execute', fy_start: start, fy_end: end })
        });
        const data = await res.json();
        if (data.ok) {
            showMsg('Year-end closing journal posted successfully! Journal ID: ' + data.journal_id + '. You should now add a period lock for ' + end + '.', 'success');
            document.getElementById('closeBtn').style.display = 'none';
            // Disable the option
            const options = document.getElementById('fiscal_year').options;
            for (let i = 0; i < options.length; i++) {
                if (options[i].value === sel) {
                    options[i].disabled = true;
                    options[i].textContent += ' (CLOSED)';
                    break;
                }
            }
            document.getElementById('fiscal_year').value = '';
        } else {
            showMsg(data.error || 'Failed to post closing journal', 'error');
        }
    } catch (err) {
        showMsg('Network error: ' + err.message, 'error');
    } finally {
        document.getElementById('closeBtn').disabled = false;
        document.getElementById('closeBtn').textContent = 'Confirm & Post Closing Journal';
    }
}
</script>
<script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
