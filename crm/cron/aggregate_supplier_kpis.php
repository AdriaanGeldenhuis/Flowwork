<?php
// CLI: php crm/cron/aggregate_supplier_kpis.php
// Recalculates the supplier delivery figures (on time %, average hours to
// delivery, cancelled deliveries %) shown on supplier pages. The background
// tick (/notifications/api/tick.php) already does this once a day; run this
// by hand after a large import of purchase orders.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../lib/CrmNotify.php';

try {
    $n = CrmNotify::supplierKpis($DB);
    echo "Supplier delivery figures updated for {$n} supplier(s).\n";
} catch (Throwable $e) {
    error_log('Supplier KPI aggregation error: ' . $e->getMessage());
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
