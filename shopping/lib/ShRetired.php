<?php
// /shopping/lib/ShRetired.php — Shopping was rebuilt (Maison pages, one JSON
// API in /shopping/api/). The deploy copies files but never deletes any, so
// every older endpoint is kept as a tiny stub that answers 410 Gone: nothing
// can still write through the old code paths (they let anyone in the company
// change or close someone else's private list, and wrote unescaped AI text
// into the page). Stubs touch neither the session nor the database.

final class ShRetired
{
    public static function gone(string $replacement = '/shopping/api/'): void
    {
        http_response_code(410);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'error' => 'This Shopping address was replaced. Reload Shopping and try again.', 'use' => $replacement]);
        exit;
    }
}
