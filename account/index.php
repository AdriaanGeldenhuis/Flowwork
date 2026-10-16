<?php
// /account/index.php — "Signed-in devices": every phone, tablet, computer and
// app install currently signed in to this account, with sign-out per device.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

$theme = (($_COOKIE['fw_theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
$firstName = (string)($_SESSION['user_first_name'] ?? '');
$assetVersion = '2026-10-16-devices';
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <meta name="theme-color" content="<?= $theme === 'dark' ? '#111015' : '#f5f4f1' ?>">
    <title>Signed-in devices · Flowwork</title>
    <link rel="stylesheet" href="/account/css/account.css?v=<?= $assetVersion ?>">
</head>
<body class="fwa" data-theme="<?= $theme ?>">
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
    <symbol id="a-back" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></symbol>
    <symbol id="a-phone" viewBox="0 0 24 24"><rect x="6" y="2.5" width="12" height="19" rx="2.5"/><path d="M10.5 18h3"/></symbol>
    <symbol id="a-tablet" viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="18" rx="2.5"/><path d="M10.5 18h3"/></symbol>
    <symbol id="a-laptop" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="11" rx="1.8"/><path d="M2 19h20"/></symbol>
    <symbol id="a-app" viewBox="0 0 24 24"><rect x="6" y="2.5" width="12" height="19" rx="2.5"/><path d="M9.5 9.5 12 8l2.5 1.5v3L12 14l-2.5-1.5z"/></symbol>
    <symbol id="a-shield" viewBox="0 0 24 24"><path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6z"/><path d="m9 12 2 2 4-4"/></symbol>
    <symbol id="a-out" viewBox="0 0 24 24"><path d="M15 17l5-5-5-5"/><path d="M20 12H9"/><path d="M12 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h6"/></symbol>
</svg>

<header class="fwa-top">
    <a class="fwa-back" href="/home.php"><svg class="fwa-i"><use href="#a-back"/></svg><span>Home</span></a>
</header>

<main class="fwa-main">
    <div class="fwa-head">
        <span class="fwa-head__icon"><svg class="fwa-i"><use href="#a-shield"/></svg></span>
        <div>
            <h1>Signed-in devices</h1>
            <p>You can be signed in on your phone, tablet, computer and the Flowwork app at the same time.
               Signing in on one never signs the others out. Sign out a device here if it's lost or no longer yours.</p>
        </div>
    </div>

    <div class="fwa-bar" id="othersBar" hidden>
        <span id="countLabel"></span>
        <button class="fwa-btn fwa-btn--danger" type="button" id="revokeOthers">
            <svg class="fwa-i"><use href="#a-out"/></svg><span>Sign out all other devices</span>
        </button>
    </div>

    <ul class="fwa-list" id="devices" aria-live="polite" aria-busy="true">
        <li class="fwa-card fwa-card--skel"></li>
        <li class="fwa-card fwa-card--skel"></li>
    </ul>

    <p class="fwa-note">Devices you stay signed in on remain signed in while you use them and sign out
        automatically after a long time without use. Changing your password signs out every device.</p>
</main>

<div class="fwa-toast" id="toast" role="status" aria-live="polite" hidden></div>

<script src="/account/js/account.js?v=<?= $assetVersion ?>"></script>
</body>
</html>
