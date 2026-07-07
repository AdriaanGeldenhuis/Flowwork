<?php
// /finances/reports/ar_tieout.php
// Legacy report variant, superseded by /finances/reports.php.
// This page was never linked from the app and depended on the gl_report_map table,
// which no migration ever creates (the page was fatally broken).
// It now redirects permanently to the maintained report.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Location: /finances/reports.php', true, 301);
exit;
