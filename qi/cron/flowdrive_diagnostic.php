<?php
// /qi/cron/flowdrive_diagnostic.php
//
// Read-mostly diagnostic for the FlowWork Drive publishing pipeline. Open it
// in a browser as an admin/owner:
//
//   https://www.flowwork.app/qi/cron/flowdrive_diagnostic.php          (report only)
//   https://www.flowwork.app/qi/cron/flowdrive_diagnostic.php?run=1    (also force-run the backfill)
//
// It answers the one question I cannot see from outside: does the database
// THIS app writes to also contain the fd_drives/fd_nodes rows that
// flowdrive.co.za reads? If yes, publishing works and the backfill fills the
// drive. If no, the two apps use different databases and that is the root
// cause. Everything is scoped to your own company; no secrets are printed.

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

$doRun = isset($_GET['run']) && $_GET['run'] === '1';

header('Content-Type: text/html; charset=UTF-8');
set_time_limit(0);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$rows = [];               // [status, label, detail]
function line(string $status, string $label, string $detail = ''): void {
    global $rows;
    $rows[] = [$status, $label, $detail];
}

$verdict = [];

try {
    // ---- 1. Deployment marker ------------------------------------------
    line('ok', 'Diagnostic version', '2026-07-17 — includes auto-backfill (commit a331709+)');
    line('info', 'Logged in as', ($_SESSION['email'] ?? '?') . ' (company ' . $companyId . ', role ' . h($role) . ')');

    // ---- 2. Flowwork DB identity ---------------------------------------
    $dbName = $DB->query("SELECT DATABASE()")->fetchColumn();
    $dbUser = $DB->query("SELECT CURRENT_USER()")->fetchColumn();
    line('info', 'Flowwork is connected to database', h($dbName) . '  (as ' . h($dbUser) . ')');
    line('info', 'Configured DB_HOST / DB_NAME', h(DB_HOST) . ' / ' . h(DB_NAME));

    // ---- 3. fd_* tables present in THIS database? -----------------------
    require_once __DIR__ . '/../../includes/flowdrive/FlowDriveRepo.php';
    $fdAvailable = FlowDriveRepo::available($DB);
    if ($fdAvailable) {
        line('ok', 'fd_* drive tables exist in Flowwork\'s database', 'fd_drives, fd_nodes, fd_blobs found here');
    } else {
        line('fail', 'fd_* drive tables NOT found in Flowwork\'s database',
            'The drive tables that flowdrive.co.za reads are in a DIFFERENT database than this app writes to. That is the root cause — see verdict.');
        $verdict[] = 'SEPARATE DATABASES: the fd_* tables are not in the database Flowwork connects to, so nothing Flowwork writes can reach the drive. The two apps must be pointed at the same MySQL database (align flowdrive.co.za\'s .env DB_HOST/DB_NAME/DB_USER with Flowwork\'s config.php), OR publishing must go through Flowdrive\'s API instead of direct DB writes.';
    }

    // ---- 4. fd_drives rows for this company (is drive 6 here?) ----------
    if ($fdAvailable) {
        $stmt = $DB->prepare("SELECT id, type, subtype, name, company_id FROM fd_drives WHERE company_id = ? ORDER BY id");
        $stmt->execute([$companyId]);
        $drives = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($drives) {
            $desc = [];
            foreach ($drives as $d) {
                $desc[] = "#{$d['id']} {$d['type']}/{$d['subtype']} \"{$d['name']}\"";
            }
            line('ok', 'fd_drives rows for your company', h(implode('  |  ', $desc)));
        } else {
            line('warn', 'No fd_drives rows for your company yet',
                'A drive will be created on first publish. Note: flowdrive.co.za showed you drive_id=6 — if that row is not here, this database is not the one Flowdrive reads.');
        }
        $flowworkDrive = null;
        foreach ($drives as $d) {
            if ($d['type'] === 'company' && $d['subtype'] === 'flowwork') { $flowworkDrive = $d; break; }
        }
        if ($flowworkDrive) {
            line('info', 'Your FlowWork Drive id in THIS database', '#' . $flowworkDrive['id'] .
                ' — the URL flowdrive.co.za/flowworkdrive.php?drive_id=' . $flowworkDrive['id'] . ' should match what you open');
        }
    }

    // ---- 5. Document counts for the company ----------------------------
    $count = function (string $sql) use ($DB, $companyId): int {
        try { $s = $DB->prepare($sql); $s->execute([$companyId]); return (int)$s->fetchColumn(); }
        catch (Throwable $e) { return -1; }
    };
    $nInv = $count("SELECT COUNT(*) FROM invoices WHERE company_id = ?");
    $nQuo = $count("SELECT COUNT(*) FROM quotes WHERE company_id = ?");
    $nCn  = $count("SELECT COUNT(*) FROM credit_notes WHERE company_id = ?");
    $nAcc = $count("SELECT COUNT(*) FROM crm_accounts WHERE company_id = ?");
    $nDoc = $count("SELECT COUNT(*) FROM crm_compliance_docs WHERE company_id = ? AND file_path IS NOT NULL AND file_path <> ''");
    line('info', 'Documents to publish', "invoices=$nInv, quotes=$nQuo, credit_notes=$nCn, accounts=$nAcc, compliance_files=$nDoc");

    // ---- 6. fd_nodes BEFORE (exact list.php root query) ----------------
    $rootListing = function (int $driveId) use ($DB): array {
        $stmt = $DB->prepare(
            "SELECT n.name, n.type FROM fd_nodes n
              WHERE n.drive_id = ? AND n.deleted_at IS NULL AND n.parent_id IS NULL
                AND NOT (n.type='folder' AND n.name IN ('/','.','..'))
              ORDER BY n.type DESC, n.name ASC"
        );
        $stmt->execute([$driveId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    if (!empty($flowworkDrive)) {
        $before = $rootListing((int)$flowworkDrive['id']);
        $names = $before ? implode(', ', array_column($before, 'name')) : '(empty)';
        line($before ? 'ok' : 'warn', 'Drive root BEFORE (what flowdrive.co.za shows now)', h($names));
    }

    // ---- 7. Force-run the backfill -------------------------------------
    if ($fdAvailable && $doRun) {
        require_once __DIR__ . '/../../includes/flowdrive/FlowDriveBackfill.php';
        $log = [];
        $counts = FlowDriveBackfill::runForCompany($DB, $companyId, function (string $l) use (&$log) { $log[] = $l; });
        line('ok', 'Backfill run complete', "published={$counts['ok']}, failed={$counts['fail']}");
        // stash the per-item log to print below
        $GLOBALS['__bf_log'] = $log;

        // refresh the drive handle (it may have just been created)
        $stmt = $DB->prepare("SELECT id FROM fd_drives WHERE company_id = ? AND type='company' AND subtype='flowwork' ORDER BY id LIMIT 1");
        $stmt->execute([$companyId]);
        $fwId = (int)$stmt->fetchColumn();
        if ($fwId) {
            $after = $rootListing($fwId);
            $names = $after ? implode(', ', array_column($after, 'name')) : '(still empty)';
            line($after ? 'ok' : 'fail', 'Drive root AFTER backfill (what flowdrive.co.za will show)', h($names));
            if ($after) {
                $verdict[] = 'PUBLISHING WORKS in this database. The drive root now lists: ' . h($names) . '. If flowdrive.co.za STILL shows empty after you refresh, the two apps read different databases (compare the database name above with flowdrive.co.za\'s .env).';
            } else {
                $verdict[] = 'Backfill ran but produced no root folders — check the per-item log and error-log tail below.';
            }
        }
    } elseif ($fdAvailable && !$doRun) {
        line('info', 'Backfill not run', 'Add ?run=1 to the URL to force-publish now: flowdrive_diagnostic.php?run=1');
    }

    // ---- 8. done flag --------------------------------------------------
    try {
        $stmt = $DB->prepare("SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = 'flowdrive_backfill_done'");
        $stmt->execute([$companyId]);
        $flag = $stmt->fetchColumn();
        line('info', 'Auto-backfill flag (company_settings)', $flag === false ? 'not set (dashboard has not triggered it yet)' : ('= ' . h($flag)));
    } catch (Throwable $e) {
        line('warn', 'Could not read auto-backfill flag', h($e->getMessage()));
    }

} catch (Throwable $e) {
    line('fail', 'Diagnostic error', h($e->getMessage()));
}

// ---- 9. error-log tail ------------------------------------------------
$logTail = [];
$logPath = dirname(__DIR__, 2) . '/php-error.log';
if (is_file($logPath)) {
    $size = filesize($logPath);
    $fh = @fopen($logPath, 'rb');
    if ($fh) {
        $read = 120000;
        if ($size > $read) { fseek($fh, -$read, SEEK_END); }
        $blob = stream_get_contents($fh);
        fclose($fh);
        foreach (explode("\n", $blob) as $l) {
            if (preg_match('/FlowDrive|DocumentPdfService|FlowDriveRepo|FlowDriveSync|FlowDriveBackfill|PHP Fatal/i', $l)) {
                $logTail[] = $l;
            }
        }
        $logTail = array_slice($logTail, -40);
    }
}

if (empty($verdict)) {
    $verdict[] = 'See the rows above. The decisive check is whether drive #6 (the one flowdrive.co.za showed you) appears in "fd_drives rows for your company" here — if it does, the databases are shared and running ?run=1 will fill the drive.';
}

?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>FlowWork Drive diagnostic</title>
<style>
  body { font: 14px/1.5 -apple-system, Segoe UI, Roboto, sans-serif; background:#0b0e14; color:#e5e7eb; margin:0; padding:24px; }
  h1 { font-size:18px; margin:0 0 4px; }
  h2 { font-size:14px; text-transform:uppercase; letter-spacing:.06em; color:#9aa4b2; margin:24px 0 8px; }
  table { border-collapse:collapse; width:100%; max-width:1100px; }
  td { padding:7px 10px; border-bottom:1px solid #1c2432; vertical-align:top; }
  .s { width:70px; font-weight:700; }
  .lbl { width:340px; color:#cbd5e1; }
  .ok .s{color:#34d399} .fail .s{color:#f87171} .warn .s{color:#fbbf24} .info .s{color:#60a5fa}
  pre { background:#111827; border:1px solid #1f2937; border-radius:8px; padding:12px; overflow:auto; max-width:1100px; color:#cbd5e1; }
  .verdict { background:#1e293b; border-left:3px solid #60a5fa; padding:12px 14px; border-radius:6px; max-width:1100px; margin:6px 0; }
</style></head><body>
<h1>FlowWork Drive — publishing diagnostic</h1>
<div style="color:#9aa4b2">Company <?= (int)$companyId ?> · <?= $doRun ? 'REPORT + FORCE-RUN' : 'report only (add <code>?run=1</code> to publish)' ?></div>

<h2>Checks</h2>
<table>
<?php foreach ($rows as [$st, $lbl, $det]): ?>
  <tr class="<?= h($st) ?>"><td class="s"><?= strtoupper(h($st)) ?></td><td class="lbl"><?= h($lbl) ?></td><td><?= $det /* already escaped */ ?></td></tr>
<?php endforeach; ?>
</table>

<h2>Verdict</h2>
<?php foreach ($verdict as $v): ?><div class="verdict"><?= $v /* escaped at source */ ?></div><?php endforeach; ?>

<?php if (!empty($GLOBALS['__bf_log'])): ?>
<h2>Backfill log</h2>
<pre><?= h(implode("\n", $GLOBALS['__bf_log'])) ?></pre>
<?php endif; ?>

<h2>Recent error-log lines (FlowDrive / fatals)</h2>
<pre><?= $logTail ? h(implode("\n", $logTail)) : '(none — good, or log not at ' . h($logPath) . ')' ?></pre>

<h2>Next step</h2>
<pre>If "Drive root AFTER backfill" lists Customers/Suppliers, refresh
flowdrive.co.za/flowworkdrive.php?drive_id=<?= isset($fwId) && $fwId ? (int)$fwId : 6 ?>

Still empty there but full here  ->  the two apps use DIFFERENT databases
(compare "connected to database" above with flowdrive.co.za's .env).
Send me a screenshot of this page and I'll take it from there.</pre>
</body></html>
