<?php
// POST /mail/api/view.php — saved searches in the sidebar.
//   {op:"save", id?, name, filters:{q, view, label_id, account_id, filter}}
//   {op:"delete", id}
require_once __DIR__ . '/_bootstrap.php';
mail_require_post();

$in = mail_input();
$op = mail_str($in, 'op', 10);

$list = function () use ($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID): array {
    $st = $DB->prepare("SELECT id, name, filters_json FROM mail_saved_views WHERE company_id = ? AND user_id = ? ORDER BY name");
    $st->execute([$MAIL_COMPANY_ID, $MAIL_USER_ID]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $f = json_decode((string)$v['filters_json'], true);
        $out[] = ['id' => (int)$v['id'], 'name' => $v['name'], 'filters' => is_array($f) ? $f : []];
    }
    return $out;
};

if ($op === 'delete') {
    $DB->prepare("DELETE FROM mail_saved_views WHERE id = ? AND company_id = ? AND user_id = ?")
       ->execute([(int)($in['id'] ?? 0), $MAIL_COMPANY_ID, $MAIL_USER_ID]);
    mail_json(['views' => $list()]);
}

if ($op === 'save') {
    $name = mail_str($in, 'name', 100);
    if ($name === '') mail_error('Give the search a name');
    $f = is_array($in['filters'] ?? null) ? $in['filters'] : [];
    $filters = [
        'q'          => mb_substr(trim((string)($f['q'] ?? '')), 0, 300),
        'view'       => in_array($f['view'] ?? '', MailRepo::VIEWS, true) ? $f['view'] : 'all',
        'label_id'   => (int)($f['label_id'] ?? 0),
        'account_id' => (int)($f['account_id'] ?? 0),
        'filter'     => in_array($f['filter'] ?? '', ['unread', 'starred', 'attachments'], true) ? $f['filter'] : '',
    ];
    if ($filters['q'] === '' && !$filters['label_id'] && $filters['filter'] === '') mail_error('Search for something first');
    $id = (int)($in['id'] ?? 0);
    if ($id) {
        $DB->prepare("UPDATE mail_saved_views SET name = ?, filters_json = ?, updated_at = NOW() WHERE id = ? AND company_id = ? AND user_id = ?")
           ->execute([$name, json_encode($filters), $id, $MAIL_COMPANY_ID, $MAIL_USER_ID]);
    } else {
        $DB->prepare("INSERT INTO mail_saved_views (company_id, user_id, name, filters_json, created_at) VALUES (?, ?, ?, ?, NOW())")
           ->execute([$MAIL_COMPANY_ID, $MAIL_USER_ID, $name, json_encode($filters)]);
    }
    mail_json(['views' => $list()]);
}
mail_error('Unknown operation');
