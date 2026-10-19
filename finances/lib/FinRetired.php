<?php
// finances/lib/FinRetired.php
//
// Finance moved to one page (/finances/, hash routes) with one JSON API
// (/finances/api/). The deploy copies files but never deletes any, so every
// older page and endpoint is kept as a tiny stub:
//   - a page sends the browser to the matching new screen (bookmarks, emails
//     and links from other modules keep working);
//   - an endpoint answers 410 Gone, so nothing can still write through the
//     old code paths.
// Neither touches the session or the database.

final class FinRetired
{
    /**
     * Send the browser to a Finance route. $route is like '#payables?view=aging' or '/finances/settings.php#fx';
     * $map copies old query parameters to new ones (['old' => 'new']), integers / dates only.
     */
    public static function redirect(string $route, array $map = []): void
    {
        $extra = [];
        foreach ($map as $old => $new) {
            $v = $_GET[$old] ?? null;
            if (!is_scalar($v) || $v === '') continue;
            $v = (string)$v;
            if (!preg_match('/^(\d{1,12}|\d{4}-\d{2}-\d{2}|\d{4}-\d{2}|[a-z_]{1,30})$/', $v)) continue;
            $extra[$new] = $v;
        }
        if ($route[0] === '#') {
            [$path, $q] = array_pad(explode('?', substr($route, 1), 2), 2, '');
            parse_str($q, $params);
            $params = array_merge($params, $extra);
            $target = '/finances/#' . $path . ($params ? '?' . http_build_query($params) : '');
        } else {
            $target = $route . ($extra ? (str_contains($route, '?') ? '&' : '?') . http_build_query($extra) : '');
        }
        header('Cache-Control: no-store');
        header('Location: ' . $target, true, 302);
        echo '<!doctype html><meta charset="utf-8"><title>Moved</title><p>This page moved to <a href="' . htmlspecialchars($target, ENT_QUOTES) . '">Finance</a>.</p>';
        exit;
    }

    /** An endpoint that no longer exists. */
    public static function gone(string $replacement = '/finances/api/'): void
    {
        http_response_code(410);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'success' => false, 'error' => 'This Finance address was replaced. Reload Finance and try again.', 'message' => 'This Finance address was replaced.', 'use' => $replacement]);
        exit;
    }
}
