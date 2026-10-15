<?php
// POST /mail/api/prefs.php — save mail preferences (partial updates allowed).
require_once __DIR__ . '/_bootstrap.php';
mail_require_post();

$in = mail_input();
$cur = MailRepo::prefs($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID);

$block = array_key_exists('block_external_images', $in) ? mail_bool($in, 'block_external_images') : $cur['block_external_images'];
$notify = array_key_exists('notification_enabled', $in) ? mail_bool($in, 'notification_enabled') : $cur['notification_enabled'];
$sla = array_key_exists('sla_default_hours', $in) ? max(1, min(168, (int)$in['sla_default_hours'])) : $cur['sla_default_hours'];

$settings = array_intersect_key($cur, MailRepo::PREF_DEFAULTS);
if (isset($in['undo_send_seconds'])) {
    $u = (int)$in['undo_send_seconds'];
    $settings['undo_send_seconds'] = in_array($u, [0, 5, 10, 20, 30], true) ? $u : 10;
}
if (isset($in['density'])) $settings['density'] = $in['density'] === 'compact' ? 'compact' : 'comfortable';
if (isset($in['reading_pane'])) $settings['reading_pane'] = $in['reading_pane'] === 'off' ? 'off' : 'right';
if (isset($in['default_reply'])) $settings['default_reply'] = $in['default_reply'] === 'reply_all' ? 'reply_all' : 'reply';
if (array_key_exists('smart_labels', $in)) $settings['smart_labels'] = mail_bool($in, 'smart_labels');
if (array_key_exists('sla_enabled', $in)) $settings['sla_enabled'] = mail_bool($in, 'sla_enabled');
if (array_key_exists('show_snippets', $in)) $settings['show_snippets'] = mail_bool($in, 'show_snippets');
if (isset($in['sync_minutes'])) $settings['sync_minutes'] = max(1, min(30, (int)$in['sync_minutes']));

$DB->prepare("INSERT INTO user_mail_prefs (user_id, company_id, sla_default_hours, block_external_images, notification_enabled, settings_json)
              VALUES (?, ?, ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE company_id = VALUES(company_id), sla_default_hours = VALUES(sla_default_hours),
                  block_external_images = VALUES(block_external_images), notification_enabled = VALUES(notification_enabled),
                  settings_json = VALUES(settings_json)")
   ->execute([$MAIL_USER_ID, $MAIL_COMPANY_ID, $sla, $block ? 1 : 0, $notify ? 1 : 0, json_encode($settings)]);

mail_json(['prefs' => MailRepo::prefs($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID)]);
