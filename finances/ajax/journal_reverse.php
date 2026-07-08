<?php
// /finances/ajax/journal_reverse.php — Reverse a posted journal entry
// SARS compliance: posted journals cannot be deleted or modified, only reversed.
// Creates a mirror-image journal with debits/credits swapped.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../permissions.php';
require_once __DIR__ . '/../lib/ReversalService.php';

require_method('POST');
Csrf::validate();
requireRoles(['admin', 'bookkeeper']);

header('Content-Type: application/json');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
if (!$companyId || !$userId) { json_error('Not authorised', 403); }

$input     = json_decode(file_get_contents('php://input'), true);
$journalId = isset($input['journal_id']) ? (int)$input['journal_id'] : 0;
$reason    = trim($input['reason'] ?? '');
$reversalDateIn = trim($input['reversal_date'] ?? '');

if ($journalId <= 0) { json_error('Invalid journal_id'); }
if ($reason === '') { json_error('A reason is required for reversals (SARS audit trail)'); }

// Optional explicit reversal date (YYYY-MM-DD, must be a real calendar date)
if ($reversalDateIn !== '') {
    $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $reversalDateIn)
        ? DateTime::createFromFormat('Y-m-d', $reversalDateIn) : false;
    if (!$d || $d->format('Y-m-d') !== $reversalDateIn) {
        json_error('Invalid reversal_date: ' . $reversalDateIn . ' (expected YYYY-MM-DD)');
    }
}

try {
    // Verify the journal is posted and not already reversed
    $stmt = $DB->prepare("SELECT status, reversed_by_journal_id, entry_date, description, ref_type FROM journal_entries WHERE id = ? AND company_id = ?");
    $stmt->execute([$journalId, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) { json_error('Journal not found', 404); }
    if ($row['status'] !== 'posted') { json_error('Only posted journals can be reversed', 409); }
    if ($row['reversed_by_journal_id']) { json_error('Journal has already been reversed (by #' . $row['reversed_by_journal_id'] . ')', 409); }

    // System/engine-owned journals must be backed out through their own flow —
    // void/revert an invoice, undo a bank match, unfile a VAT return, reopen a
    // year-end close. A blind manual reversal here would desync the owning
    // document's status (and, for vat_settle / year_end, the filing / close
    // state) from the GL. Manual and adjustment journals stay reversible.
    $ENGINE_OWNED = [
        'invoice', 'payment', 'credit_note', 'ap_bill', 'ap_payment', 'vendor_credit',
        'payroll', 'depreciation', 'fa_disposal', 'bank_tx', 'vat_settle', 'year_end', 'reversal',
    ];
    if (in_array($row['ref_type'], $ENGINE_OWNED, true)) {
        json_error('This is a system journal (' . $row['ref_type'] . '). Reverse it through its own flow (void / undo-match / unfile / reopen), not manual reversal.', 409);
    }

    // Resolve the reversal date: explicit payload date wins; otherwise use the
    // original entry_date when its period is still open, else fall back to today
    // so journals in locked periods remain reversible.
    require_once __DIR__ . '/../lib/PeriodService.php';
    $periods = new PeriodService($DB, $companyId);
    if ($reversalDateIn !== '') {
        $reversalDate = $reversalDateIn;
    } elseif (!$periods->isLocked($row['entry_date'])) {
        $reversalDate = $row['entry_date'];
    } else {
        $reversalDate = date('Y-m-d');
    }
    if ($periods->isLocked($reversalDate)) {
        json_error('Reversal date ' . $reversalDate . ' falls in a locked period', 409);
    }

    // ReversalService::reverseJournal throws on any failure and returns the
    // reversal journal id. Inventory movements linked to the journal are
    // reversed in the same transaction (mirrors supersedeJournal) — a
    // manually reversed stock journal otherwise kept its movements, so a
    // later re-issue decremented stock twice.
    $DB->beginTransaction();
    try {
        $service = new ReversalService($DB, $companyId);
        $reversalId = $service->reverseJournal($journalId, $userId, $reason, $reversalDate);
        require_once __DIR__ . '/../lib/InventoryService.php';
        (new InventoryService($DB, $companyId))
            ->reverseMovementsForJournal($journalId, $reversalId, $reversalDate);
        $DB->commit();
    } catch (Throwable $e) {
        if ($DB->inTransaction()) { $DB->rollBack(); }
        throw $e;
    }

    // Audit
    require_once __DIR__ . '/../lib/Audit.php';
    Audit::log('journal_reversed', [
        'original_journal_id' => $journalId,
        'reversal_journal_id' => $reversalId,
        'reason'              => $reason,
        'entry_date'          => $row['entry_date'],
        'reversal_date'       => $reversalDate,
        'description'         => $row['description']
    ]);

    echo json_encode(['ok' => true, 'data' => ['reversal_journal_id' => $reversalId]]);

} catch (Throwable $e) {
    error_log('journal_reverse: ' . $e->getMessage());
    // Business-rule failures (already reversed, locked period) surface to the
    // user; PDO/engine detail is replaced by a generic message.
    json_exception($e, 409);
}
