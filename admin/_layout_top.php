<?php
// /admin/_layout_top.php
// Shared opening markup for every admin page: <html>, <head>, <body
// class="fw-admin">, the global header, sidebar nav and main container.
//
// Inputs (set by caller before include):
//   $pageTitle  - string used in <title>
//   $company    - associative array, optional. If absent, the global
//                 header looks up the active company by $_SESSION['company_id'].
//   $user       - associative array, optional 'first_name'; falls back to session

$initialTheme = (($_COOKIE['fw_theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
$pageTitle    = $pageTitle ?? 'Admin';
$companyName  = $company['name']    ?? null;
$firstName    = $user['first_name'] ?? ($_SESSION['user_first_name'] ?? 'Welcome');
?><!DOCTYPE html>
<html lang="en" data-theme="<?= $initialTheme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="/admin/style.css?v=2026-07-09-ADMIN-UI-3D">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
</head>
<body class="fw-admin" data-theme="<?= $initialTheme ?>">
<?php
  $headerScope = 'fw-admin';
  $companyLogo = null;
  include __DIR__ . '/../includes/_global_header.php';
?>
<div class="fw-admin__shell">
    <?php include __DIR__ . '/_nav.php'; ?>

    <main class="fw-admin__main">
        <div class="fw-admin__container">
