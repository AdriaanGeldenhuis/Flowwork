<?php
// /projects/guest-logout.php — POST (with the form token) signs the guest out.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/lib/autoload.php';
require_once __DIR__ . '/partials/guest.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && pr_guest_csrf_ok()) {
    Guests::signOut();
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    header('Location: /projects/guest-login.php');
    exit;
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
pr_guest_page('Sign out?', 'Flowwork Projects', 'You will need your email and password to open the board again.',
    '<form method="post" data-busy><input type="hidden" name="csrf_token" value="' . $h(Csrf::token()) . '">
        <button class="btn btn--primary" type="submit">Sign out</button></form>
     <div class="fwp-auth__alt"><button class="btn btn--ghost btn--sm" type="button" onclick="history.back()">Stay signed in</button></div>');
