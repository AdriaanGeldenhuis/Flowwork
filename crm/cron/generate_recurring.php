<?php
// /crm/cron/generate_recurring.php
// Recurring-invoice generation belongs to the QI module. This shim exists only
// because the server crontab may still point here — repoint the crontab to
// /qi/cron/generate_recurring.php and delete this file.
require __DIR__ . '/../../qi/cron/generate_recurring.php';
