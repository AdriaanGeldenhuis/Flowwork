<?php
// /projects/api/export.php — a board as a CSV file that opens in Excel.
//   GET ?board_id=3[&ids=1,2,3]   (ids: export only these items, e.g. the filtered view)
// Cells that start with = + - @ are prefixed with ' so spreadsheets never run them.
require_once __DIR__ . '/_bootstrap.php';

$boardId = (int)($_GET['board_id'] ?? 0);
$b = $PR->requireBoard($boardId, 1);
pr_rate_limit('pr-export', 30, 600);
$boards = new BoardRepo($PR);
$cols = array_values(array_filter($boards->columnRows($boardId), fn($c) => $c['type'] !== 'files'));
$only = !empty($_GET['ids']) ? array_flip(pr_ids((string)$_GET['ids'], 20000)) : null;

$groups = [];
foreach ($boards->groupRows($boardId) as $g) $groups[(int)$g['id']] = $g['name'];
$names = array_map(fn($p) => $p['name'], $PR->people());
$suppliers = [];
if (array_filter($cols, fn($c) => $c['type'] === 'supplier')) {
    $st = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ?");
    $st->execute([$PR_COMPANY_ID]);
    $suppliers = $st->fetchAll(PDO::FETCH_KEY_PAIR);
}
$ctx = ['userNames' => $names, 'supplierNames' => $suppliers];

$st = $DB->prepare("SELECT i.* FROM board_items i LEFT JOIN board_groups g ON g.id = i.group_id
                     WHERE i.board_id = ? AND i.company_id = ? AND (i.archived = 0 OR i.archived IS NULL)
                  ORDER BY g.position, i.position, i.id");
$st->execute([$boardId, $PR_COMPANY_ID]);
$items = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), fn($i) => $only === null || isset($only[(int)$i['id']])));
$vals = [];
if ($items) {
    $in = implode(',', array_map(fn($i) => (int)$i['id'], $items));
    foreach ($DB->query("SELECT item_id, column_id, value FROM board_item_values WHERE item_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $vals[(int)$v['item_id']][(int)$v['column_id']] = $v['value'];
    }
}

$safe = function ($v): string {
    $v = (string)$v;
    if ($v !== '' && strpos('=+-@', $v[0]) !== false && !is_numeric($v)) $v = "'" . $v;
    return $v;
};
$file = preg_replace('/[^A-Za-z0-9 _.-]+/', '', $b['title']) ?: 'board';
while (ob_get_level() > 0) ob_end_clean();
header_remove('Content-Type');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $file . ' ' . date('Y-m-d') . '.csv"');
header('X-Content-Type-Options: nosniff');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
$head = ['Group', 'Name'];
foreach ($cols as $c) {
    if ($c['type'] === 'timeline') { $head[] = $c['name'] . ' start'; $head[] = $c['name'] . ' end'; }
    else $head[] = $c['name'];
}
$head[] = 'Created';
$head[] = 'Last updated';
fputcsv($out, array_map($safe, $head));
foreach ($items as $it) {
    $row = [$groups[(int)$it['group_id']] ?? '', $it['title']];
    $raw = $vals[(int)$it['id']] ?? [];
    foreach ($cols as $c) {
        $v = $raw[(int)$c['column_id']] ?? null;
        switch ($c['type']) {
            case 'timeline':
                $t = Columns::decode($c, $v);
                $row[] = $t['start'] ?? '';
                $row[] = $t['end'] ?? '';
                break;
            case 'number': case 'formula': case 'progress': case 'rating':
                $d = Columns::decode($c, $v);
                $row[] = $d === null ? '' : (is_float($d) ? rtrim(rtrim(number_format($d, 6, '.', ''), '0'), '.') : $d);
                break;
            case 'created':
                $row[] = substr((string)$it['created_at'], 0, 16) . ' · ' . ($names[(int)$it['created_by']] ?? '');
                break;
            case 'updated':
                $row[] = substr((string)$it['updated_at'], 0, 16);
                break;
            default:
                $row[] = Columns::text($c, $v, $ctx);
        }
    }
    $row[] = substr((string)$it['created_at'], 0, 16);
    $row[] = substr((string)$it['updated_at'], 0, 16);
    fputcsv($out, array_map($safe, $row));
}
fclose($out);
Activity::log($DB, $PR_COMPANY_ID, $PR_USER_ID, $boardId, null, 'board_exported', ['items' => count($items)]);
exit;
