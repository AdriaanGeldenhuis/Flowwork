<?php
// /qi/cron/flowdrive_diagnostic.php
//
// Admin-only diagnostic for the FlowWork Drive publishing pipeline. Open it in
// a browser while logged in:
//
//   https://www.flowwork.app/qi/cron/flowdrive_diagnostic.php
//
// It always force-runs the publish (idempotent) and reports, in one screen,
// everything needed to explain an empty drive: which database this app uses,
// whether the fd_* tables and your drive row live in it, the environment
// (PHP/GD/memory), a live single-document test render WITH the exact exception
// if it fails, the drive's node counts and root listing, and the tail of the
// error log. Scoped to your own company; no secrets are printed.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'owner'], true)) {
    http_response_code(403);
    exit('Admin access required');
}
$companyId = (int)($_SESSION['company_id'] ?? 0);
if ($companyId <= 0) {
    http_response_code(403);
    exit('No active company');
}

header('Content-Type: text/html; charset=UTF-8');
set_time_limit(0);
ignore_user_abort(true);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$rows = [];
function line(string $status, string $label, string $detail = ''): void {
    global $rows;
    $rows[] = [$status, $label, $detail];
}
$verdict = [];
$bfLog = [];
$fwId = 0;

try {
    require_once __DIR__ . '/../../includes/flowdrive/FlowDriveRepo.php';
    require_once __DIR__ . '/../../includes/flowdrive/FlowDriveSync.php';
    require_once __DIR__ . '/../services/DocumentPdfService.php';
    require_once __DIR__ . '/../../includes/flowdrive/FlowDriveBackfill.php';

    // ---- 1. Identity / deployment --------------------------------------
    line('info', 'Diagnostic build', '2026-07-17b — error-surfacing');
    line('info', 'Logged in as', ($_SESSION['email'] ?? '?') . ' (company ' . $companyId . ', role ' . h($role) . ')');
    $dbName = $DB->query("SELECT DATABASE()")->fetchColumn();
    $dbUser = $DB->query("SELECT CURRENT_USER()")->fetchColumn();
    line('info', 'Connected database', h($dbName) . '  (as ' . h($dbUser) . ')');

    // ---- 2. Environment ------------------------------------------------
    $gd = extension_loaded('gd');
    $webp = $gd && function_exists('gd_info') && !empty(gd_info()['WebP Support']);
    line($gd ? 'ok' : 'warn', 'PHP / GD', 'PHP ' . PHP_VERSION . ' · GD ' . ($gd ? 'yes' : 'NO')
        . ' · WebP ' . ($webp ? 'yes' : 'no') . ' · memory_limit=' . ini_get('memory_limit'));
    $storageDir = dirname(__DIR__, 2) . '/storage/qi';
    $storageWritable = (is_dir($storageDir) && is_writable($storageDir)) || is_writable(dirname($storageDir));
    line($storageWritable ? 'ok' : 'warn', 'storage/qi writable', $storageWritable ? 'yes' : 'NO (PDF files won\'t save, but drive publishing does not depend on this)');

    // ---- 3. fd_* tables present ----------------------------------------
    $fdAvailable = FlowDriveRepo::available($DB);
    if ($fdAvailable) {
        line('ok', 'fd_* drive tables in THIS database', 'fd_drives / fd_nodes / fd_blobs found');
    } else {
        line('fail', 'fd_* drive tables NOT in this database', 'The drive tables live elsewhere — align the databases.');
        $verdict[] = 'SEPARATE DATABASES: fd_* tables are not where Flowwork writes.';
    }

    // ---- 4. Drives for this company ------------------------------------
    if ($fdAvailable) {
        $stmt = $DB->prepare("SELECT id, type, subtype, name FROM fd_drives WHERE company_id = ? ORDER BY id");
        $stmt->execute([$companyId]);
        $drives = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $desc = [];
        foreach ($drives as $d) {
            $desc[] = "#{$d['id']} {$d['type']}/{$d['subtype']}";
            if ($d['type'] === 'company' && $d['subtype'] === 'flowwork') { $fwId = (int)$d['id']; }
        }
        line($drives ? 'ok' : 'warn', 'fd_drives for your company', $drives ? h(implode('  |  ', $desc)) : '(none yet — will be created)');
        if ($fwId) {
            line('info', 'Your FlowWork Drive id', '#' . $fwId . ' → open flowdrive.co.za/flowworkdrive.php?drive_id=' . $fwId);
        }
    }

    // ---- 5. Counts -----------------------------------------------------
    $count = function (string $sql) use ($DB, $companyId): int {
        try { $s = $DB->prepare($sql); $s->execute([$companyId]); return (int)$s->fetchColumn(); }
        catch (Throwable $e) { return -1; }
    };
    $nInv = $count("SELECT COUNT(*) FROM invoices WHERE company_id = ?");
    $nQuo = $count("SELECT COUNT(*) FROM quotes WHERE company_id = ?");
    $nCn  = $count("SELECT COUNT(*) FROM credit_notes WHERE company_id = ?");
    line('info', 'Documents to publish', "invoices=$nInv · quotes=$nQuo · credit_notes=$nCn");

    // ---- 6. LIVE single-document test render (surfaces the real error) --
    if ($fdAvailable) {
        $firstInv = $DB->prepare("SELECT id, invoice_number FROM invoices WHERE company_id = ? ORDER BY id LIMIT 1");
        $firstInv->execute([$companyId]);
        $sample = $firstInv->fetch(PDO::FETCH_ASSOC);
        if ($sample) {
            DocumentPdfService::$lastError = null;
            $r = DocumentPdfService::render($DB, $companyId, 'invoice', (int)$sample['id']);
            if ($r !== null && !empty($r['bytes']) && strncmp($r['bytes'], '%PDF', 4) === 0) {
                line('ok', 'Test render invoice ' . h($sample['invoice_number']), 'produced a ' . strlen($r['bytes']) . '-byte PDF');
            } else {
                line('fail', 'Test render invoice ' . h($sample['invoice_number']) . ' FAILED',
                    'This is why the drive is empty — every document render fails the same way. Exact error:<br><b>'
                    . h(DocumentPdfService::$lastError ?: 'render returned null with no captured error') . '</b>');
                $verdict[] = 'RENDER FAILS: PDF generation throws for every document, so nothing is published. The exact error is shown above — fix that and re-run.';
            }
        } else {
            line('warn', 'No invoices to test-render', 'will still try quotes/credit notes below');
        }
    }

    // ---- 7. Force-run the full backfill --------------------------------
    if ($fdAvailable) {
        FlowDriveSync::$lastError = null;
        FlowDriveRepo::$lastError = null;
        $counts = FlowDriveBackfill::runForCompany($DB, $companyId, function (string $l) use (&$bfLog) { $bfLog[] = $l; });
        line($counts['ok'] > 0 ? 'ok' : 'fail', 'Backfill run', "published={$counts['ok']} · failed={$counts['fail']}");
        if (FlowDriveSync::$lastError) {
            line('warn', 'Last publish error', h(FlowDriveSync::$lastError));
        }
        if (FlowDriveRepo::$lastError) {
            line('warn', 'Last drive-write error', h(FlowDriveRepo::$lastError));
        }

        // resolve (possibly newly created) drive
        if (!$fwId) {
            $stmt = $DB->prepare("SELECT id FROM fd_drives WHERE company_id = ? AND type='company' AND subtype='flowwork' ORDER BY id LIMIT 1");
            $stmt->execute([$companyId]);
            $fwId = (int)$stmt->fetchColumn();
        }
        if ($fwId) {
            $tot = $DB->prepare("SELECT COUNT(*) FROM fd_nodes WHERE drive_id = ? AND deleted_at IS NULL");
            $tot->execute([$fwId]);
            $totN = (int)$tot->fetchColumn();
            $stmt = $DB->prepare(
                "SELECT n.name, n.type FROM fd_nodes n
                  WHERE n.drive_id = ? AND n.deleted_at IS NULL AND n.parent_id IS NULL
                    AND NOT (n.type='folder' AND n.name IN ('/','.','..'))
                  ORDER BY n.type DESC, n.name ASC"
            );
            $stmt->execute([$fwId]);
            $after = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $names = $after ? implode(', ', array_column($after, 'name')) : '(empty)';
            line($after ? 'ok' : 'fail', 'Drive root AFTER (what Flowdrive shows)', h($names) . " — $totN total nodes on drive #$fwId");
            if ($after) {
                $verdict[] = 'PUBLISHING WORKS: drive #' . $fwId . ' root now lists ' . h($names) . '. Refresh flowdrive.co.za/flowworkdrive.php?drive_id=' . $fwId . ' (make sure the URL uses drive_id=' . $fwId . ').';
            }
        }
    }

} catch (Throwable $e) {
    line('fail', 'Diagnostic crashed', h($e->getMessage()) . ' [' . h(basename($e->getFile())) . ':' . $e->getLine() . ']');
}

// ---- 8. error-log tail ------------------------------------------------
$logTail = [];
foreach ([dirname(__DIR__, 2) . '/php-error.log', ini_get('error_log')] as $logPath) {
    if ($logPath && is_file($logPath)) {
        $fh = @fopen($logPath, 'rb');
        if ($fh) {
            $size = filesize($logPath);
            if ($size > 150000) { fseek($fh, -150000, SEEK_END); }
            $blob = stream_get_contents($fh);
            fclose($fh);
            foreach (explode("\n", $blob) as $l) {
                if (preg_match('/FlowDrive|DocumentPdfService|QiStyledPdf|PHP Fatal|Uncaught/i', $l)) {
                    $logTail[] = $l;
                }
            }
        }
        break;
    }
}
$logTail = array_slice($logTail, -50);

if (empty($verdict)) {
    $verdict[] = 'Inconclusive — read the failed rows above and the error-log tail.';
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>FlowWork Drive diagnostic</title>
<style>
  body{font:14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;background:#0b0e14;color:#e5e7eb;margin:0;padding:24px}
  h1{font-size:18px;margin:0 0 4px}
  h2{font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:#9aa4b2;margin:22px 0 8px}
  table{border-collapse:collapse;width:100%;max-width:1150px}
  td{padding:7px 10px;border-bottom:1px solid #1c2432;vertical-align:top}
  .s{width:64px;font-weight:700}.lbl{width:300px;color:#cbd5e1}
  .ok .s{color:#34d399}.fail .s{color:#f87171}.warn .s{color:#fbbf24}.info .s{color:#60a5fa}
  pre{background:#111827;border:1px solid #1f2937;border-radius:8px;padding:12px;overflow:auto;max-width:1150px;color:#cbd5e1;white-space:pre-wrap;word-break:break-word}
  .verdict{background:#1e293b;border-left:3px solid #60a5fa;padding:12px 14px;border-radius:6px;max-width:1150px;margin:6px 0}
</style></head><body>
<h1>FlowWork Drive — publishing diagnostic</h1>
<div style="color:#9aa4b2">Company <?= (int)$companyId ?> · report + force-run</div>

<h2>Checks</h2>
<table>
<?php foreach ($rows as [$st, $lbl, $det]): ?>
  <tr class="<?= h($st) ?>"><td class="s"><?= strtoupper(h($st)) ?></td><td class="lbl"><?= h($lbl) ?></td><td><?= $det ?></td></tr>
<?php endforeach; ?>
</table>

<h2>Verdict</h2>
<?php foreach ($verdict as $v): ?><div class="verdict"><?= $v ?></div><?php endforeach; ?>

<?php if ($bfLog): ?><h2>Backfill log</h2><pre><?= h(implode("\n", $bfLog)) ?></pre><?php endif; ?>

<h2>Error-log tail (FlowDrive / renderer / fatals)</h2>
<pre><?= $logTail ? h(implode("\n", $logTail)) : '(no matching lines found)' ?></pre>

<h2>Next step</h2>
<pre>Screenshot THIS whole page and send it to me. The "Test render" row and the
error-log tail pinpoint exactly why the drive is empty. If "Drive root AFTER"
lists Customers/Suppliers, open flowdrive.co.za/flowworkdrive.php?drive_id=<?= $fwId ?: 6 ?></pre>
</body></html>
