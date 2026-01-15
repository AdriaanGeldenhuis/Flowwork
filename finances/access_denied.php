<?php
// access_denied.php
// Simple page displayed when a user lacks permission to view a finance resource.

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

// Basic HTML structure for access denied
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied</title>
    <link rel="stylesheet" href="/finances/assets/finance.css?v=2025-02-10-PERM">
</head>
<body>
    <main class="fw-finance" style="padding: 2rem; text-align: center;">
        <h1 style="margin-top: 2rem; font-size: 2rem;">Access Denied</h1>
        <p style="font-size: 1.1rem; margin-top: 1rem;">You do not have permission to access this page.</p>
        <p style="margin-top: 2rem;"><a href="/">Return to Home</a></p>
    </main>
</body>
</html>