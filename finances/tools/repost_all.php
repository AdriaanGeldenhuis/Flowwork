<?php
// finances/tools/repost_all.php
//
// CLI migration tool: re-post every posted source document through the
// corrected PostingService so pre-remediation data lands on the current
// engine. The GL stays immutable — every repost reverses the old journal
// (linked reversal chain) and posts a replacement, exactly like the UI.
//
// Usage:
//   php finances/tools/repost_all.php [--company=N] [--user=N] [--dry-run]
//
//   --company=N  only this company (default: all companies)
//   --user=N     user id recorded as poster/auditor (default: 1)
//   --dry-run    list what would be reposted without touching the ledger
//
// Per document: OK (reposted), SKIP (engine forbids, e.g. paid AP bills),
// FAIL (posting threw — document left as it was; the per-document
// transaction rolled back).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This tool is CLI-only.\n";
    exit(1);
}

require_once __DIR__ . '/../../db.php'; // defines $DB (PDO)
require_once __DIR__ . '/../lib/PostingService.php';
require_once __DIR__ . '/../lib/InventoryService.php';

$options = getopt('', ['company::', 'user::', 'dry-run']);
$onlyCompany = isset($options['company']) ? (int)$options['company'] : 0;
$userId = isset($options['user']) ? max(1, (int)$options['user']) : 1;
$dryRun = array_key_exists('dry-run', $options);

// Companies to process
if ($onlyCompany > 0) {
    $companyIds = [$onlyCompany];
} else {
    $companyIds = array_map('intval', $DB->query("SELECT id FROM companies ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
}
if (!$companyIds) {
    fwrite(STDERR, "No companies found.\n");
    exit(1);
}

$grand = ['ok' => 0, 'skip' => 0, 'fail' => 0];

/**
 * Run one posting call, print the outcome, update counters.
 */
function attempt(array &$counts, string $label, callable $fn, bool $dryRun): void
{
    if ($dryRun) {
        echo "DRY   $label\n";
        return;
    }
    try {
        $fn();
        $counts['ok']++;
        echo "OK    $label\n";
    } catch (Throwable $e) {
        $counts['fail']++;
        echo "FAIL  $label — " . $e->getMessage() . "\n";
    }
}

foreach ($companyIds as $cid) {
    echo "==== Company $cid ====\n";
    $svc = new PostingService($DB, $cid, $userId);
    $counts = ['ok' => 0, 'skip' => 0, 'fail' => 0];

    // Legacy stock movements (journal_id IS NULL — the old engines never
    // linked them) must be neutralised BEFORE the repost, in the same
    // transaction: supersedeJournal only reverses journal-linked movements,
    // so a repost would otherwise issue/receive the stock a second time.
    $inventory = new InventoryService($DB, $cid);
    $repostWithLegacyStock = function (string $refType, int $docId, string $date, callable $post) use ($DB, $inventory) {
        $DB->beginTransaction();
        try {
            $inventory->reverseLegacyMovementsFor($refType, $docId, $date);
            $post();
            $DB->commit();
        } catch (Throwable $e) {
            if ($DB->inTransaction()) {
                $DB->rollBack();
            }
            throw $e;
        }
    };

    // --- Invoices: posted (not draft/cancelled, journal linked) -------------
    $stmt = $DB->prepare(
        "SELECT id, invoice_number, issue_date FROM invoices
          WHERE company_id = ? AND journal_id IS NOT NULL
            AND status NOT IN ('draft','cancelled')
            AND deleted_at IS NULL
          ORDER BY issue_date, id"
    );
    $stmt->execute([$cid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        attempt($counts, "invoice #{$row['id']} ({$row['invoice_number']})",
            fn() => $repostWithLegacyStock('invoice', (int)$row['id'], $row['issue_date'],
                fn() => $svc->postInvoice((int)$row['id'])), $dryRun);
    }

    // --- AP bills: posted are reposted; paid are reported and skipped -------
    $stmt = $DB->prepare(
        "SELECT id, vendor_invoice_number, status, issue_date FROM ap_bills
          WHERE company_id = ? AND status IN ('posted','paid')
          ORDER BY issue_date, id"
    );
    $stmt->execute([$cid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $label = "ap_bill #{$row['id']} (" . ($row['vendor_invoice_number'] ?: '-') . ")";
        if ($row['status'] === 'paid') {
            // The engine always rejects reposting settled bills — reversing a
            // paid bill's journal would orphan the payment allocations.
            $counts['skip']++;
            echo "SKIP  $label — bill is paid; engine forbids reposting settled bills\n";
            continue;
        }
        attempt($counts, $label,
            fn() => $repostWithLegacyStock('ap_bill', (int)$row['id'], $row['issue_date'],
                fn() => $svc->postApBill((int)$row['id'], true)), $dryRun);
    }

    // --- Credit notes --------------------------------------------------------
    $stmt = $DB->prepare(
        "SELECT id, credit_note_number FROM credit_notes
          WHERE company_id = ? AND journal_id IS NOT NULL
            AND status NOT IN ('draft','cancelled')
          ORDER BY issue_date, id"
    );
    $stmt->execute([$cid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        attempt($counts, "credit_note #{$row['id']} ({$row['credit_note_number']})",
            fn() => $svc->postCreditNote((int)$row['id']), $dryRun);
    }

    // --- Customer payments (receipts) ----------------------------------------
    $stmt = $DB->prepare(
        "SELECT id, reference FROM payments
          WHERE company_id = ? AND journal_id IS NOT NULL
          ORDER BY payment_date, id"
    );
    $stmt->execute([$cid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        attempt($counts, "payment #{$row['id']} (" . ($row['reference'] ?: '-') . ")",
            fn() => $svc->postCustomerPayment((int)$row['id']), $dryRun);
    }

    // --- Supplier payments ----------------------------------------------------
    $stmt = $DB->prepare(
        "SELECT id, reference FROM ap_payments
          WHERE company_id = ? AND journal_id IS NOT NULL
          ORDER BY payment_date, id"
    );
    $stmt->execute([$cid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        attempt($counts, "ap_payment #{$row['id']} (" . ($row['reference'] ?: '-') . ")",
            fn() => $svc->postSupplierPayment((int)$row['id']), $dryRun);
    }

    // --- Vendor credits --------------------------------------------------------
    $stmt = $DB->prepare(
        "SELECT id, credit_number FROM vendor_credits
          WHERE company_id = ? AND journal_id IS NOT NULL
            AND status <> 'cancelled'
          ORDER BY issue_date, id"
    );
    $stmt->execute([$cid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        attempt($counts, "vendor_credit #{$row['id']} ({$row['credit_number']})",
            fn() => $svc->postVendorCredit((int)$row['id']), $dryRun);
    }

    echo "---- Company $cid summary: OK={$counts['ok']} SKIP={$counts['skip']} FAIL={$counts['fail']}"
        . ($dryRun ? ' (dry run — nothing posted)' : '') . "\n\n";
    foreach ($grand as $k => $v) {
        $grand[$k] += $counts[$k];
    }
}

echo "==== TOTAL: OK={$grand['ok']} SKIP={$grand['skip']} FAIL={$grand['fail']}"
    . ($dryRun ? ' (dry run)' : '') . "\n";
exit($grand['fail'] > 0 ? 2 : 0);
