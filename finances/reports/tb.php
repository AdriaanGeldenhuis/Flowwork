<?php
// /finances/reports/tb.php
// Legacy report variant, superseded by /finances/reports.php.
// This page was never linked from the app and duplicated a maintained report.
// It now redirects permanently to the maintained report.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Location: /finances/reports.php', true, 301);
exit;
