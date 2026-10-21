<?php
// /suppliers_ai/lib/SaRetired.php — Suppliers AI was rebuilt (Maison pages, one
// JSON API in /suppliers_ai/api/). The deploy copies files but never deletes
// any, so every older endpoint is kept as a tiny stub that answers 410 Gone:
// nothing can still write through the old code paths (they had no CSRF-safe
// JSON API, stored AI-invented phone numbers as suppliers, made "RFQs" as
// empty purchase orders with random numbers and created CRM suppliers on the
// side). Stubs touch neither the session nor the database.

final class SaRetired
{
    public static function gone(string $replacement = '/suppliers_ai/api/'): void
    {
        http_response_code(410);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'error' => 'This Suppliers AI address was replaced. Reload Suppliers AI and try again.', 'use' => $replacement]);
        exit;
    }
}
