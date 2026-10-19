<?php
// /finances/partials/head.php — <head> + opening <body> shared by every Finance page.
// Built on the Maison design system: /mail/css/mail.css first, then /finances/css/finance.css.
// Inputs: $pageTitle, $boot (FinBoot::payload), $bodyExtraClass (optional), $headExtra (optional HTML)
$fwTheme = (($_COOKIE['fw_theme'] ?? 'dark') === 'light') ? 'light' : 'dark';
$finV = function (string $rel): string {
    return (string)(@filemtime(__DIR__ . '/../../' . ltrim($rel, '/')) ?: '1');
};
$finBodyClass = trim('fwm fwf ' . ($boot['prefs']['density'] === 'compact' ? 'is-compact ' : '') . 'text-' . $boot['prefs']['text'] . ' ' . ($bodyExtraClass ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <meta name="theme-color" content="<?= $fwTheme === 'dark' ? '#0d0a09' : '#f2ece3' ?>">
    <meta name="robots" content="noindex">
    <title><?= htmlspecialchars($pageTitle) ?> · <?= htmlspecialchars($boot['company']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,300;0,6..72,400;0,6..72,500;1,6..72,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/mail/css/mail.css?v=<?= $finV('mail/css/mail.css') ?>">
    <link rel="stylesheet" href="/finances/css/finance.css?v=<?= $finV('finances/css/finance.css') ?>">
    <?php if ($finStyle = FinBoot::styleVars($boot)): ?><style id="finVars"><?= $finStyle ?></style><?php endif; ?>
    <?= $headExtra ?? '' ?>
</head>
<body class="<?= htmlspecialchars($finBodyClass) ?>" data-theme="<?= $fwTheme ?>">
<?php include __DIR__ . '/../../mail/partials/icons.php'; ?>
<?php include __DIR__ . '/../../calendar/partials/icons.php'; ?>
<?php include __DIR__ . '/../../projects/partials/icons.php'; ?>
<?php include __DIR__ . '/../../qi/partials/icons.php'; ?>
<?php include __DIR__ . '/icons.php'; ?>
