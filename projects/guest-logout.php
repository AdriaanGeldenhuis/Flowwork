<?php
require_once __DIR__ . '/../init.php';

unset($_SESSION['guest_id']);
unset($_SESSION['guest_email']);
unset($_SESSION['guest_board_id']);
unset($_SESSION['guest_token']);

session_destroy();

header('Location: /projects/guest-login.php');
exit;