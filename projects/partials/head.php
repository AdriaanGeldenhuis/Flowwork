<?php
// /projects/partials/head.php — <head> + opening <body> shared by every Projects page.
// Projects is built on the Mail design system: /mail/css/mail.css first, then
// /projects/css/projects.css. Inputs: $pageTitle, $companyName, $bodyExtraClass (optional)
$fwTheme = (($_COOKIE['fw_theme'] ?? 'dark') === 'light') ? 'light' : 'dark';
$prV = function (string $rel): string {
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;1,6..72,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/mail/css/mail.css?v=<?= $prV('mail/css/mail.css') ?>">
    <link rel="stylesheet" href="/projects/css/projects.css?v=<?= $prV('projects/css/projects.css') ?>">
</head>
<body class="fwm fwp <?= htmlspecialchars($bodyExtraClass ?? '') ?>" data-theme="<?= $fwTheme ?>">
<?php include __DIR__ . '/../../mail/partials/icons.php'; ?>
<?php include __DIR__ . '/../../calendar/partials/icons.php'; ?>
<?php include __DIR__ . '/icons.php'; ?>
