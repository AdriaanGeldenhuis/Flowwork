<?php
// /finances/ajax/bank_import.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../permissions.php';

header('Content-Type: application/json');

require_method('POST');
Csrf::validate();
requireRoles(['admin', 'bookkeeper']);

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
if (!$companyId || !$userId) {
    echo json_encode(['ok' => false, 'error' => 'Not authorised']);
    exit;
}

$bankAccountId = isset($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : 0;

if ($bankAccountId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Bank account ID required']);
    exit;
}

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'No file uploaded']);
    exit;
}

// Enforce file size limit (10MB) to prevent memory exhaustion
if ($_FILES['csv_file']['size'] > 10 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'error' => 'File too large (max 10MB)']);
    exit;
}

/**
 * Normalize a South African bank statement amount string to a float.
 * Handles '1,250.00', '1 250.00', 'R1 250.00', '(1 250.00)' (negative),
 * trailing minus '1250.00-' and decimal comma '1250,00'.
 * Returns null when the value is genuinely non-numeric.
 */
function fw_bank_normalize_amount(string $raw): ?float
{
    $s = trim($raw);
    if ($s === '') {
        return null;
    }
    $negative = false;
    // Parentheses denote a negative amount: (1 250.00)
    if (preg_match('/^\((.+)\)$/', $s, $m)) {
        $negative = true;
        $s = trim($m[1]);
    }
    // Strip a leading currency indicator (R / ZAR)
    $s = preg_replace('/^(?:ZAR|R)\s*/i', '', $s, 1);
    // Trailing minus: 1250.00-
    if (substr($s, -1) === '-') {
        $negative = true;
        $s = rtrim(substr($s, 0, -1));
    }
    // Leading minus
    if ($s !== '' && $s[0] === '-') {
        $negative = true;
        $s = ltrim(substr($s, 1));
    }
    // Remove spaces (incl. non-breaking) used as thousands separators
    $s = str_replace(["\xC2\xA0", ' '], '', $s);
    // Decimal comma with no dot present: 1250,00 -> 1250.00
    if (strpos($s, '.') === false && preg_match('/^\d+,\d{1,2}$/', $s)) {
        $s = str_replace(',', '.', $s);
    } else {
        // Comma thousands separators: 1,250.00 -> 1250.00
        $s = str_replace(',', '', $s);
    }
    if ($s === '' || !is_numeric($s)) {
        return null;
    }
    $v = (float)$s;
    return $negative ? -$v : $v;
}

/**
 * Parse a statement date (YYYY-MM-DD, DD/MM/YYYY or MM/DD/YYYY) to Y-m-d, or null.
 */
function fw_bank_parse_date(string $rawDate): ?string
{
    $dateObj = DateTime::createFromFormat('Y-m-d', $rawDate);
    if ($dateObj && $dateObj->format('Y-m-d') === $rawDate) {
        return $rawDate;
    }
    $dateObj = DateTime::createFromFormat('d/m/Y', $rawDate);
    if (!$dateObj) {
        $dateObj = DateTime::createFromFormat('m/d/Y', $rawDate);
    }
    return $dateObj ? $dateObj->format('Y-m-d') : null;
}

try {
    $DB->beginTransaction();

    // Verify bank account belongs to company
    $stmt = $DB->prepare("SELECT name FROM gl_bank_accounts WHERE id = ? AND company_id = ?");
    $stmt->execute([$bankAccountId, $companyId]);
    $bankAccount = $stmt->fetch();

    if (!$bankAccount) {
        throw new Exception('Bank account not found');
    }

    // Record an import batch. Use the new bank_import_batches table to
    // track this upload. We treat the auto-increment id as the batch
    // identifier. Use the uploaded file's name for the file_name
    // column.
    $stmt = $DB->prepare("
        INSERT INTO bank_import_batches (
            company_id, bank_account_id, file_name, imported_by
        ) VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        $companyId,
        $bankAccountId,
        $_FILES['csv_file']['name'] ?? 'import.csv',
        $userId
    ]);
    $batchId = (string)$DB->lastInsertId();

    // ── Pass 1: parse and validate every CSV row (no inserts yet) ──
    // The first row is only treated as a header when its date column does
    // NOT parse as a date or its amount column does NOT parse as a number.
    $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
    $rows = [];
    $skippedInvalid = 0;
    $isFirstRow = true;

    while (($row = fgetcsv($file)) !== false) {
        $wasFirstRow = $isFirstRow;
        $isFirstRow = false;

        if (count($row) < 3) {
            if (!$wasFirstRow) { $skippedInvalid++; }
            continue;
        }

        $rawDate = trim((string)$row[0]);
        $description = trim((string)$row[1]);
        $rawAmount = trim((string)$row[2]);
        $reference = isset($row[3]) ? trim((string)$row[3]) : null;

        // Validate and normalize date (accept YYYY-MM-DD, DD/MM/YYYY, MM/DD/YYYY)
        $txDate = fw_bank_parse_date($rawDate);
        // Normalize SA bank amount formats (R prefix, space/comma thousands, parentheses)
        $amount = fw_bank_normalize_amount($rawAmount);

        if ($txDate === null || $amount === null) {
            if ($wasFirstRow) {
                continue; // Header row — discard without counting as skipped
            }
            $skippedInvalid++;
            continue;
        }

        $rows[] = [
            'tx_date'      => $txDate,
            'description'  => $description,
            'amount_cents' => (int)round($amount * 100),
            'reference'    => $reference,
        ];
    }

    fclose($file);

    // ── De-duplicate on the SAME import_hash the PDF path uses ──
    // (bank_import_confirm.php). Sharing the key means a CSV import and a
    // later PDF re-import (or vice-versa) of the same statement don't both
    // land, and the UNIQUE(company_id, bank_account_id, import_hash) index is
    // the concurrent-double-submit backstop. The occurrence index (#n) lets
    // two genuinely identical same-day lines (e.g. two equal card fees) both
    // import, while including `reference` avoids collapsing distinct rows.
    $importCount = 0;
    $skippedExisting = 0;
    $dupSeen = [];

    if (!empty($rows)) {
        // Legacy snapshot of rows imported BEFORE import_hash existed
        // (import_hash IS NULL — the migration backfills nothing). Without this,
        // re-importing a pre-existing statement would double-import, since the
        // hash pre-check below never matches a NULL row and the unique index
        // treats every NULL as distinct. Keyed on the old (date|desc|amount)
        // tuple with counts; each legacy row absorbs at most one file row.
        $dates = array_column($rows, 'tx_date');
        $legacyStmt = $DB->prepare("
            SELECT tx_date, description, amount_cents, COUNT(*) AS cnt
            FROM gl_bank_transactions
            WHERE company_id = ? AND bank_account_id = ?
              AND tx_date BETWEEN ? AND ? AND import_hash IS NULL
            GROUP BY tx_date, description, amount_cents
        ");
        $legacyStmt->execute([$companyId, $bankAccountId, min($dates), max($dates)]);
        $legacy = [];
        while ($lr = $legacyStmt->fetch(PDO::FETCH_ASSOC)) {
            $legacy[$lr['tx_date'] . '|' . $lr['description'] . '|' . $lr['amount_cents']] = (int)$lr['cnt'];
        }

        $checkStmt = $DB->prepare("
            SELECT COUNT(*) FROM gl_bank_transactions
            WHERE company_id = ? AND bank_account_id = ? AND import_hash = ?
        ");
        $insert = $DB->prepare("
            INSERT INTO gl_bank_transactions (
                company_id, bank_account_id, tx_date, description,
                amount_cents, reference, matched, import_batch_id, import_hash, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, NOW())
        ");

        foreach ($rows as $r) {
            $dupKey = $r['tx_date'] . '|' . $r['description'] . '|' . $r['amount_cents'] . '|' . ($r['reference'] ?? '');
            $dupSeen[$dupKey] = ($dupSeen[$dupKey] ?? 0) + 1;
            $importHash = sha1($dupKey . '|#' . $dupSeen[$dupKey]);

            // 1) Already imported via the hash key (this path or the PDF path).
            $checkStmt->execute([$companyId, $bankAccountId, $importHash]);
            if ($checkStmt->fetchColumn() > 0) {
                $skippedExisting++;
                continue;
            }
            // 2) Matches a pre-hash (legacy NULL-hash) row.
            $legacyKey = $r['tx_date'] . '|' . $r['description'] . '|' . $r['amount_cents'];
            if (!empty($legacy[$legacyKey])) {
                $legacy[$legacyKey]--;
                $skippedExisting++;
                continue;
            }
            $insert->execute([
                $companyId,
                $bankAccountId,
                $r['tx_date'],
                $r['description'],
                $r['amount_cents'],
                $r['reference'],
                $batchId,
                $importHash
            ]);
            $importCount++;
        }
    }

    $skippedCount = $skippedInvalid + $skippedExisting;

    // Update bank account balance
    $stmt = $DB->prepare("
        UPDATE gl_bank_accounts 
        SET current_balance_cents = opening_balance_cents + (
            SELECT COALESCE(SUM(amount_cents), 0) 
            FROM gl_bank_transactions 
            WHERE bank_account_id = ?
        )
        WHERE id = ?
    ");
    $stmt->execute([$bankAccountId, $bankAccountId]);

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'bank_statement_imported', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['bank_account_id' => $bankAccountId, 'count' => $importCount]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode([
        'ok' => true,
        'data' => [
            'count'            => $importCount,
            'imported'         => $importCount,
            'skipped'          => $skippedCount,
            'skipped_existing' => $skippedExisting,
            'skipped_invalid'  => $skippedInvalid
        ]
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Bank import error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to import bank statement'
    ]);
}