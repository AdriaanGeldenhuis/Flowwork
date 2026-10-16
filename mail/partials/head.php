<?php
// /mail/partials/head.php — <head> + opening <body> shared by every Mail page.
// Inputs: $pageTitle, $companyName, $bodyExtraClass (optional)
if (!defined('MAIL_ASSET_VERSION')) define('MAIL_ASSET_VERSION', '2026-10-16-devices');
$fwTheme = (($_COOKIE['fw_theme'] ?? 'dark') === 'light') ? 'light' : 'dark';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <meta name="theme-color" content="<?= $fwTheme === 'dark' ? '#0d0a09' : '#f2ece3' ?>">
    <title><?= htmlspecialchars($pageTitle) ?> · <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;1,6..72,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/mail/css/mail.css?v=<?= MAIL_ASSET_VERSION ?>">
</head>
<body class="fwm <?= htmlspecialchars($bodyExtraClass ?? '') ?>" data-theme="<?= $fwTheme ?>">
<?php include __DIR__ . '/icons.php'; ?>
