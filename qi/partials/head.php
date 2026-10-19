<?php
// /qi/partials/head.php — <head> + opening <body> shared by every Quotes & Invoices page.
// Built on the Mail design system: /mail/css/mail.css first, then /qi/css/qi.css.
// Inputs: $pageTitle, $companyName, $bodyExtraClass (optional), $headExtra (optional HTML)
$fwTheme = (($_COOKIE['fw_theme'] ?? 'dark') === 'light') ? 'light' : 'dark';
$qiV = function (string $rel): string {
    return (string)(@filemtime(__DIR__ . '/../../' . ltrim($rel, '/')) ?: '1');
};
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,300;0,6..72,400;0,6..72,500;1,6..72,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/mail/css/mail.css?v=<?= $qiV('mail/css/mail.css') ?>">
    <link rel="stylesheet" href="/qi/css/qi.css?v=<?= $qiV('qi/css/qi.css') ?>">
    <?= $headExtra ?? '' ?>
</head>
<body class="fwm fwq <?= htmlspecialchars($bodyExtraClass ?? '') ?>" data-theme="<?= $fwTheme ?>">
<?php include __DIR__ . '/../../mail/partials/icons.php'; ?>
<?php include __DIR__ . '/../../calendar/partials/icons.php'; ?>
<?php include __DIR__ . '/../../projects/partials/icons.php'; ?>
<?php include __DIR__ . '/icons.php'; ?>
