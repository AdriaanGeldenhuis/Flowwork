<?php
// /qi/ajax/yoco_webhook.php
// DEPRECATED duplicate of /webhooks/yoco.php.
//
// This endpoint used to run a second, weaker copy of the Yoco reconciliation
// logic (it did not gate on the event type and settled the full balance on any
// signed delivery). To eliminate that divergence it now delegates to the single
// canonical handler, so whichever URL Yoco is configured to call behaves
// identically. Point the Yoco webhook at /webhooks/yoco.php; this shim can be
// removed once no deliveries reach it.
require __DIR__ . '/../../webhooks/yoco.php';
