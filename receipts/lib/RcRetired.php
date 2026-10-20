<?php
// /receipts/lib/RcRetired.php — Receipts was rebuilt (Maison pages, one JSON
// API in /receipts/api/). The deploy copies files but never deletes any, so
// every older endpoint is kept as a tiny stub that answers 410 Gone: nothing
// can still write through the old code paths (they made sales invoices out
// of supplier slips, invented amounts when reading failed and served slips
// to anyone with the link). Stubs touch neither the session nor the database.

final class RcRetired
{
    public static function gone(string $replacement = '/receipts/api/'): void
    {
        http_response_code(410);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'error' => 'This Receipts address was replaced. Reload Receipts and try again.', 'use' => $replacement]);
        exit;
    }
}
