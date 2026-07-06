<?php
// /finances/ajax/year_end_close.php
//
// Year-end closing endpoint. Supports two actions:
//   - preview: Returns the closing journal lines without posting
//   - execute: Creates and posts the closing journal entry
//
// The closing process:
// 1. Sums all posted journal lines for revenue and expense accounts within the fiscal year
// 2. Creates a journal entry that reverses (zeroes out) each account's balance
// 3. Posts the net difference to Retained Earnings (account code 3100)

$__fin_root = realpath(__DIR__ . '/../../');
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

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

requireRoles(['admin']);

$companyId = $_SESSION['company_id'] ?? null;
$userId    = $_SESSION['user_id'] ?? null;
if (!$companyId || !$userId) {
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

$action  = $input['action'] ?? '';
$fyStart = $input['fy_start'] ?? '';
$fyEnd   = $input['fy_end'] ?? '';

if (!in_array($action, ['preview', 'execute'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid action']);
    exit;
}
if (!$fyStart || !$fyEnd) {
    echo json_encode(['ok' => false, 'error' => 'Fiscal year dates required']);
    exit;
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fyStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fyEnd)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid date format']);
    exit;
}

$closingRef = 'YE-CLOSE-' . substr($fyStart, 0, 4) . '-' . substr($fyEnd, 0, 4);

// Check if already closed
$stmt = $DB->prepare(
    "SELECT COUNT(*) FROM journal_entries WHERE company_id = ? AND reference = ? AND status = 'posted'"
);
$stmt->execute([$companyId, $closingRef]);
if ((int)$stmt->fetchColumn() > 0) {
    echo json_encode(['ok' => false, 'error' => 'This fiscal year has already been closed']);
    exit;
}

// Resolve Retained Earnings account code. Try 3100 first, then look up by subtype.
$retainedCode = null;
$stmt = $DB->prepare(
    "SELECT account_code FROM gl_accounts WHERE company_id = ? AND account_code = '3100' AND is_active = 1 LIMIT 1"
);
$stmt->execute([$companyId]);
$retainedCode = $stmt->fetchColumn();

if (!$retainedCode) {
    // Fallback: find by account_subtype
    $stmt = $DB->prepare(
        "SELECT account_code FROM gl_accounts WHERE company_id = ? AND account_subtype = 'retained_earnings' AND is_active = 1 ORDER BY account_code LIMIT 1"
    );
    $stmt->execute([$companyId]);
    $retainedCode = $stmt->fetchColumn();
}
if (!$retainedCode) {
    echo json_encode(['ok' => false, 'error' => 'Retained Earnings account (3100) not found. Please ensure your Chart of Accounts includes this account.']);
    exit;
}

// Get retained earnings account name
$stmt = $DB->prepare("SELECT account_name FROM gl_accounts WHERE company_id = ? AND account_code = ? LIMIT 1");
$stmt->execute([$companyId, $retainedCode]);
$retainedName = $stmt->fetchColumn() ?: 'Retained Earnings';

try {
    // Sum all posted journal line balances for revenue and expense accounts in the fiscal year
    $stmt = $DB->prepare(
        "SELECT ga.account_code, ga.account_name, ga.account_type,
                SUM(jl.debit) AS total_debit, SUM(jl.credit) AS total_credit
         FROM journal_lines jl
         JOIN journal_entries je ON je.id = jl.journal_id
         JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = ?
         WHERE je.company_id = ?
           AND je.status = 'posted'
           AND je.entry_date >= ?
           AND je.entry_date <= ?
           AND ga.account_type IN ('revenue', 'expense')
         GROUP BY ga.account_code, ga.account_name, ga.account_type
         HAVING (SUM(jl.debit) - SUM(jl.credit)) != 0
         ORDER BY ga.account_code"
    );
    $stmt->execute([$companyId, $companyId, $fyStart, $fyEnd]);
    $balances = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($balances)) {
        echo json_encode(['ok' => true, 'lines' => [], 'net_income_cents' => 0]);
        exit;
    }

    // Build closing journal lines
    // Revenue accounts normally have credit balances -> debit them to close
    // Expense accounts normally have debit balances -> credit them to close
    $lines = [];
    $netIncomeCents = 0; // positive = profit, negative = loss

    foreach ($balances as $bal) {
        $totalDebit  = round(floatval($bal['total_debit']) * 100);
        $totalCredit = round(floatval($bal['total_credit']) * 100);
        $balance = $totalDebit - $totalCredit; // positive = debit balance, negative = credit balance

        if ($balance == 0) continue;

        if ($bal['account_type'] === 'revenue') {
            // Revenue has credit balance (negative $balance), debit to close
            // Net income increases by the credit balance
            $netIncomeCents += (-$balance); // credit balance contributes to net income
            $lines[] = [
                'account_code' => $bal['account_code'],
                'account_name' => $bal['account_name'],
                'debit_cents'  => ($balance < 0) ? abs($balance) : 0,
                'credit_cents' => ($balance > 0) ? $balance : 0
            ];
        } else {
            // Expense has debit balance (positive $balance), credit to close
            // Net income decreases by the debit balance
            $netIncomeCents -= $balance; // debit balance reduces net income
            $lines[] = [
                'account_code' => $bal['account_code'],
                'account_name' => $bal['account_name'],
                'debit_cents'  => ($balance < 0) ? abs($balance) : 0,
                'credit_cents' => ($balance > 0) ? $balance : 0
            ];
        }
    }

    // Add Retained Earnings line for the net difference
    if ($netIncomeCents > 0) {
        // Profit: credit Retained Earnings
        $lines[] = [
            'account_code' => $retainedCode,
            'account_name' => $retainedName,
            'debit_cents'  => 0,
            'credit_cents' => $netIncomeCents
        ];
    } elseif ($netIncomeCents < 0) {
        // Loss: debit Retained Earnings
        $lines[] = [
            'account_code' => $retainedCode,
            'account_name' => $retainedName,
            'debit_cents'  => abs($netIncomeCents),
            'credit_cents' => 0
        ];
    }

    if ($action === 'preview') {
        echo json_encode([
            'ok' => true,
            'lines' => $lines,
            'net_income_cents' => $netIncomeCents
        ]);
        exit;
    }

    // --- Execute: Create and post the closing journal ---

    // Require prior years to be closed first: if P&L activity exists before
    // this fiscal year and no year-end close journal covers it, closing this
    // year would strand that income outside retained earnings.
    $stmt = $DB->prepare(
        "SELECT COUNT(*)
           FROM journal_lines jl
           JOIN journal_entries je ON je.id = jl.journal_id
           JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id
          WHERE je.company_id = ? AND je.status = 'posted'
            AND je.entry_date < ?
            AND COALESCE(je.module,'') <> 'year_end'
            AND ga.account_type IN ('revenue','expense')
            AND NOT EXISTS (
                SELECT 1 FROM journal_entries pc
                 WHERE pc.company_id = je.company_id AND pc.module = 'year_end'
                   AND pc.status = 'posted' AND pc.entry_date >= je.entry_date
                   AND pc.entry_date < ?
            )"
    );
    $stmt->execute([$companyId, $fyStart, $fyStart]);
    if ((int)$stmt->fetchColumn() > 0) {
        echo json_encode(['ok' => false, 'error' => 'Prior fiscal year(s) contain unclosed income/expense activity — close earlier years first']);
        exit;
    }

    // Verify journal balances before posting
    $verifyDebit = 0;
    $verifyCredit = 0;
    foreach ($lines as $line) {
        $verifyDebit += $line['debit_cents'];
        $verifyCredit += $line['credit_cents'];
    }
    if ($verifyDebit !== $verifyCredit) {
        echo json_encode(['ok' => false, 'error' => 'Closing journal is unbalanced (debits ' . $verifyDebit . ' != credits ' . $verifyCredit . '). Please contact support.']);
        exit;
    }

    $DB->beginTransaction();

    // Create journal entry header
    $entryDate = $fyEnd; // Closing entry dated at fiscal year end
    $description = 'Year-end closing – ' . $fyStart . ' to ' . $fyEnd;

    $stmt = $DB->prepare(
        "INSERT INTO journal_entries (company_id, entry_date, reference, description, status, module, created_by, created_at, posted_by, posted_at)
         VALUES (?, ?, ?, ?, 'posted', 'year_end', ?, NOW(), ?, NOW())"
    );
    $stmt->execute([$companyId, $entryDate, $closingRef, $description, $userId, $userId]);
    $journalId = (int)$DB->lastInsertId();

    // Insert journal lines
    foreach ($lines as $line) {
        $debit  = $line['debit_cents'] / 100;
        $credit = $line['credit_cents'] / 100;
        if ($debit == 0 && $credit == 0) continue;

        $stmt = $DB->prepare(
            "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit)
             VALUES (?, ?, ?, ?, ?)"
        );
        $lineDesc = ($line['account_code'] === $retainedCode)
            ? 'Net income transfer to Retained Earnings'
            : 'Close ' . $line['account_code'] . ' – ' . $line['account_name'];
        $stmt->execute([$journalId, $line['account_code'], $lineDesc, $debit, $credit]);
    }

    // Lock the closed fiscal year (through fy_end) so nothing can post into
    // it after the close — a year-end close that leaves the year open is a
    // stale close waiting to happen.
    $stmt = $DB->prepare(
        "INSERT INTO gl_period_locks (company_id, lock_date, lock_reason, locked_by, locked_at, is_active)
         VALUES (?, ?, ?, ?, NOW(), 1)"
    );
    $stmt->execute([$companyId, $fyEnd, 'year_end_close_' . substr($fyEnd, 0, 4), $userId]);

    // Audit log
    $stmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
         VALUES (?, ?, 'year_end_close', ?, ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $userId,
        json_encode([
            'journal_id' => $journalId,
            'fy_start' => $fyStart,
            'fy_end' => $fyEnd,
            'net_income_cents' => $netIncomeCents,
            'accounts_closed' => count($lines) - 1
        ]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode([
        'ok' => true,
        'journal_id' => $journalId,
        'net_income_cents' => $netIncomeCents
    ]);

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('Year-end close error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to process year-end closing: ' . $e->getMessage()]);
}
