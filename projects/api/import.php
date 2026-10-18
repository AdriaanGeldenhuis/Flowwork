<?php
// /projects/api/import.php — add items from a CSV (or Excel "CSV UTF-8") file.
//   POST multipart {board_id, group_id?, file}
// The header row is matched to the board's columns by name (any case); the
// column called Name / Item / Title / Task (or the first one) becomes the
// item's name, and a "Group" column puts rows in groups of that name. People
// can be names or email addresses; dates may be 2026-10-21, 21/10/2026 or
// 21-10-2026. Cells that do not fit their column are skipped and reported.
require_once __DIR__ . '/_bootstrap.php';
pr_require_post();
pr_rate_limit('pr-import', 10, 600);

$boardId = (int)($_POST['board_id'] ?? 0);
$PR->requireBoard($boardId, 2);
$f = $_FILES['file'] ?? null;
if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) pr_error('Choose a CSV file to import.');
if ((int)$f['size'] > 5 * 1024 * 1024) pr_error('The file is too large (5 MB at most).');
$ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'txt'], true)) pr_error('Save the spreadsheet as CSV (UTF-8) first, then import that file.');

$raw = file_get_contents($f['tmp_name']);
if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
$first = strtok($raw, "\n");
$delim = substr_count((string)$first, ';') > substr_count((string)$first, ',') ? ';' : (substr_count((string)$first, "\t") > substr_count((string)$first, ',') ? "\t" : ',');
$fh = fopen('php://temp', 'r+');
fwrite($fh, $raw);
rewind($fh);
$header = fgetcsv($fh, 0, $delim, '"', '');
if (!$header || !array_filter($header, fn($h) => trim((string)$h) !== '')) pr_error('The file has no header row.');

$boards = new BoardRepo($PR);
$items = new ItemRepo($PR);
$cols = $boards->columnRows($boardId);
$byName = [];
foreach ($cols as $c) $byName[Formula::nameKey($c['name'])] = $c;
$groups = [];
foreach ($boards->groupRows($boardId) as $g) $groups[Formula::nameKey($g['name'])] = (int)$g['id'];
$defaultGroup = (int)($_POST['group_id'] ?? 0) ?: (reset($groups) ?: null);

$titleIdx = null;
$groupIdx = null;
$map = [];
foreach ($header as $i => $h) {
    $k = Formula::nameKey((string)$h);
    if ($titleIdx === null && in_array($k, ['name', 'item', 'title', 'task', 'item name'], true)) { $titleIdx = $i; continue; }
    if ($groupIdx === null && $k === 'group') { $groupIdx = $i; continue; }
    if (isset($byName[$k]) && !in_array($byName[$k]['type'], Columns::COMPUTED, true)) $map[$i] = $byName[$k];
}
if ($titleIdx === null) { $titleIdx = 0; unset($map[0]); }

$people = $PR->people();
$personByKey = [];
foreach ($people as $p) { $personByKey[mb_strtolower($p['name'])] = $p['id']; $personByKey[mb_strtolower((string)$p['email'])] = $p['id']; }
$suppliers = [];
if (array_filter($map, fn($c) => $c['type'] === 'supplier')) {
    $st = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'supplier'");
    $st->execute([$PR_COMPANY_ID]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) $suppliers[mb_strtolower($s['name'])] = (int)$s['id'];
}
$toDate = function (string $v): ?string {
    $v = trim($v);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})#', $v, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    $t = strtotime($v);
    return $t ? date('Y-m-d', $t) : null;
};

$added = 0;
$skipped = [];
$noName = 0;   // rows without a name: only the first few are listed, the rest counted
$read = 0;
$groupsFull = false;
$line = 1;
while (($row = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
    $line++;
    if ($added >= 2000) { $skipped[] = 'Stopped after 2 000 rows.'; break; }
    if (!array_filter($row, fn($v) => trim((string)$v) !== '')) continue;
    if (++$read > 20000) { $skipped[] = 'Stopped after reading 20 000 rows.'; break; }
    $title = trim((string)($row[$titleIdx] ?? ''));
    if ($title === '') { if (++$noName <= 10 && count($skipped) < 50) $skipped[] = "Row $line: no name"; continue; }
    $gid = $defaultGroup;
    if ($groupIdx !== null && ($gname = trim((string)($row[$groupIdx] ?? ''))) !== '') {
        $gk = Formula::nameKey($gname);
        if (!isset($groups[$gk])) {
            // At the group limit, rows naming a new group go to the default group (0: the board's first).
            try {
                $groups[$gk] = $groupsFull ? ($defaultGroup ?? 0) : $boards->createGroup($boardId, $gname, null, 'bottom')['id'];
            } catch (ProjectUserError $e) {
                $groups[$gk] = $defaultGroup ?? 0;
                $groupsFull = true;
                $skipped[] = "Row $line: " . $e->getMessage() . ' Rows with new group names from here on went to the default group.';
            }
        }
        $gid = $groups[$gk];
    }
    $values = [];
    foreach ($map as $i => $c) {
        $v = trim((string)($row[$i] ?? ''));
        if ($v === '') continue;
        switch ($c['type']) {
            case 'people':
                $ids = [];
                foreach (preg_split('/\s*[,;]\s*/', $v) as $who) if (isset($personByKey[mb_strtolower($who)])) $ids[] = $personByKey[mb_strtolower($who)];
                $v = $ids;
                break;
            case 'date':
                $v = $toDate($v);
                break;
            case 'timeline':
                $parts = preg_split('/\s+(?:-|–|to)\s+/', $v);
                $v = ['start' => $toDate($parts[0] ?? ''), 'end' => $toDate($parts[1] ?? ($parts[0] ?? ''))];
                break;
            case 'supplier':
                $v = $suppliers[mb_strtolower($v)] ?? null;
                break;
            case 'checkbox':
                $v = in_array(mb_strtolower($v), ['1', 'yes', 'y', 'true', 'x', '✓'], true) ? '1' : '0';
                break;
            case 'progress':
                $v = str_replace('%', '', $v);
                break;
        }
        if ($v === null || $v === []) continue;
        try {
            Columns::encodeValue($c, $v, ['users' => array_fill_keys(array_keys($people), true)]);
            $values[(int)$c['column_id']] = $v;
        } catch (ProjectUserError $e) {
            if (count($skipped) < 50) $skipped[] = "Row $line, " . $c['name'] . ': ' . $e->getMessage();
        }
    }
    try {
        $items->create($boardId, $gid, $title, $values, 'bottom', ['quiet' => true, 'no_payload' => true]);
    } catch (ProjectUserError $e) {
        // e.g. the board is full: keep what was added and report where it stopped
        $skipped[] = "Stopped at row $line: " . $e->getMessage();
        break;
    }
    $added++;
}
fclose($fh);
if ($noName > 10 || ($noName && count($skipped) >= 50)) $skipped[] = "$noName rows had no name and were skipped.";
Activity::log($DB, $PR_COMPANY_ID, $PR_USER_ID, $boardId, null, 'board_imported', ['items' => $added]);
pr_json(['added' => $added, 'skipped' => $skipped, 'matched' => array_values(array_map(fn($c) => $c['name'], $map))]);
