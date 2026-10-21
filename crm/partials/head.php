<?php
// /crm/partials/head.php — <head> + opening <body> shared by every CRM page.
// Built on the Mail design system: /mail/css/mail.css first, then /crm/css/crm.css.
// Inputs: $pageTitle, $boot (CrmBoot::payload), $bodyExtraClass (optional), $headExtra (optional HTML)
$fwTheme = (($_COOKIE['fw_theme'] ?? 'dark') === 'light') ? 'light' : 'dark';
$crmV = function (string $rel): string {
    return (string)(@filemtime(__DIR__ . '/../../' . ltrim($rel, '/')) ?: '1');
};
$crmBodyClass = trim('fwm fwc ' . ($boot['prefs']['density'] === 'compact' ? 'is-compact ' : '') . 'text-' . $boot['prefs']['text'] . ' ' . ($bodyExtraClass ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <meta name="theme-color" content="<?= $fwTheme === 'dark' ? '#0d0a09' : '#f2ece3' ?>">
    <title><?= htmlspecialchars($pageTitle) ?> · <?= htmlspecialchars($boot['company']) ?></title>
    <?php include __DIR__ . '/../../includes/favicon.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,300;0,6..72,400;0,6..72,500;1,6..72,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/mail/css/mail.css?v=<?= $crmV('mail/css/mail.css') ?>">
    <link rel="stylesheet" href="/crm/css/crm.css?v=<?= $crmV('crm/css/crm.css') ?>">
    <?php if ($crmStyle = CrmBoot::styleVars($boot)): ?><style id="crmVars"><?= $crmStyle ?></style><?php endif; ?>
    <?= $headExtra ?? '' ?>
</head>
<body class="<?= htmlspecialchars($crmBodyClass) ?>" data-mod="crm" data-theme="<?= $fwTheme ?>">
<?php include __DIR__ . '/../../mail/partials/icons.php'; ?>
<?php include __DIR__ . '/../../calendar/partials/icons.php'; ?>
<?php include __DIR__ . '/../../projects/partials/icons.php'; ?>
<?php include __DIR__ . '/../../qi/partials/icons.php'; ?>
<?php include __DIR__ . '/icons.php'; ?>
