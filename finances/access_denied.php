<?php
// access_denied.php
// Simple page displayed when a user lacks permission to view a finance resource.

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', FIN_ASSET_VERSION);

// Basic HTML structure for access denied
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-finance">
    <div class="fw-finance__container">

        <?php $finTitle = 'Access Denied'; $finBack = '/finances/'; include __DIR__ . '/partials/header.php'; ?>

        <main class="fw-finance__main" style="padding: 2rem; text-align: center;">
            <h1 style="margin-top: 2rem; font-size: 2rem;">Access Denied</h1>
            <p style="font-size: 1.1rem; margin-top: 1rem;">You do not have permission to access this page.</p>
            <p style="margin-top: 2rem;"><a href="/">Return to Home</a></p>
        </main>

        <footer class="fw-finance__footer">
            <span>Access Denied v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>

    </div>

    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
