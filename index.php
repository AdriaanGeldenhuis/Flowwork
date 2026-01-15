<?php
require_once __DIR__ . '/session.php';

if (!empty($_SESSION['user_id'])) {
  header('Location: /home.php');
  exit;
}

header('Location: /login.php');
exit;