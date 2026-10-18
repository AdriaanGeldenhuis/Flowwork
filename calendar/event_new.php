<?php
// /calendar/event_new.php — older "new event" / "edit event" links open the
// editor in the calendar app (?new=1, or ?event=ID&edit=1).
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

$id = (int)($_GET['id'] ?? 0);
header('Location: /calendar/' . ($id > 0 ? '?event=' . $id . '&edit=1' : '?new=1'), true, 302);
exit;
