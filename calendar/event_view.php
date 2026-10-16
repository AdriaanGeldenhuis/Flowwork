<?php
// /calendar/event_view.php — older links (notifications, reminder emails, the
// phone app) open the event in the calendar app. auth_gate.php has already
// acted on ?fw_nid / ?fw_co from a notification tap.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

$id = (int)($_GET['id'] ?? 0);
header('Location: /calendar/' . ($id > 0 ? '?event=' . $id : ''), true, 302);
exit;
