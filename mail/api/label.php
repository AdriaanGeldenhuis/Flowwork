<?php
// POST /mail/api/label.php
//   {op:"apply", keys:[...], tag_id, on:bool}       label / unlabel conversations
//   {op:"create", name, color}                       new company label
//   {op:"update", tag_id, name, color}               rename / recolour (company labels)
//   {op:"delete", tag_id}                            delete (company labels)
require_once __DIR__ . '/_bootstrap.php';
mail_require_post();

$in = mail_input();
$op = mail_str($in, 'op', 10);

$color = function (array $in): string {
    $c = mail_str($in, 'color', 7);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : '#7b1e2b';
};
$ownLabel = function (int $tagId) use ($DB, $MAIL_COMPANY_ID): void {
    $st = $DB->prepare("SELECT company_id FROM email_tags WHERE tag_id = ?");
    $st->execute([$tagId]);
    $cid = $st->fetchColumn();
    if ($cid === false) mail_error('Label not found', 404);
    if ((int)$cid !== $MAIL_COMPANY_ID) mail_error('Built-in labels cannot be changed');
};

switch ($op) {
    case 'apply':
        $keys = mail_keys($in);
        $tagId = (int)($in['tag_id'] ?? 0);
        if (!MailRepo::labelAllowed($DB, $MAIL_COMPANY_ID, $tagId)) mail_error('Label not found', 404);
        $rows = MailRepo::emailsForKeys($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, $keys, 'all');
        if (!$rows) mail_error('Conversation not found', 404);
        $n = MailActions::label($DB, $rows, $tagId, mail_bool($in, 'on', true));
        mail_json(['affected' => $n]);

    case 'create':
        $name = mail_str($in, 'name', 64);
        if ($name === '') mail_error('Give the label a name');
        $exists = $DB->prepare("SELECT tag_id FROM email_tags WHERE company_id IN (?, 0) AND name = ?");
        $exists->execute([$MAIL_COMPANY_ID, $name]);
        if ($exists->fetchColumn()) mail_error('A label with that name already exists');
        $DB->prepare("INSERT INTO email_tags (company_id, name, color, color_hex) VALUES (?, ?, ?, ?)")
           ->execute([$MAIL_COMPANY_ID, $name, $color($in), $color($in)]);
        mail_json(['tag_id' => (int)$DB->lastInsertId(), 'labels' => MailRepo::labels($DB, $MAIL_COMPANY_ID)]);

    case 'update':
        $tagId = (int)($in['tag_id'] ?? 0);
        $ownLabel($tagId);
        $name = mail_str($in, 'name', 64);
        if ($name === '') mail_error('Give the label a name');
        $dupe = $DB->prepare("SELECT tag_id FROM email_tags WHERE company_id = ? AND name = ? AND tag_id <> ?");
        $dupe->execute([$MAIL_COMPANY_ID, $name, $tagId]);
        if ($dupe->fetchColumn()) mail_error('A label with that name already exists');
        $DB->prepare("UPDATE email_tags SET name = ?, color = ?, color_hex = ? WHERE tag_id = ? AND company_id = ?")
           ->execute([$name, $color($in), $color($in), $tagId, $MAIL_COMPANY_ID]);
        mail_json(['labels' => MailRepo::labels($DB, $MAIL_COMPANY_ID)]);

    case 'delete':
        $tagId = (int)($in['tag_id'] ?? 0);
        $ownLabel($tagId);
        $DB->beginTransaction();
        $DB->prepare("DELETE FROM email_tag_map WHERE tag_id = ?")->execute([$tagId]);
        $DB->prepare("DELETE FROM email_tags WHERE tag_id = ? AND company_id = ?")->execute([$tagId, $MAIL_COMPANY_ID]);
        $DB->commit();
        mail_json(['labels' => MailRepo::labels($DB, $MAIL_COMPANY_ID)]);
}
mail_error('Unknown operation');
