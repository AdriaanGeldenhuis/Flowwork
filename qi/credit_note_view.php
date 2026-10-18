<?php
// /qi/credit_note_view.php — kept so existing links keep working: a credit note now lives in /qi/view.php.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
$id = (int)($_GET['id'] ?? 0);
header('Location: ' . ($id > 0 ? '/qi/view.php?type=credit_note&id=' . $id : '/qi/#credit_notes'), true, 302);
