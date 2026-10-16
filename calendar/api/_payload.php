<?php
// /calendar/api/_payload.php — response shapes shared by the API and the calendar pages.

/** Calendars as the client sees them. */
function cal_calendars_payload(CalendarRepo $cal): array
{
    global $DB;
    $out = [];
    $counts = [];
    $ids = array_keys($cal->visibleCalendars());
    if ($ids) {
        $st = $DB->query("SELECT calendar_id, COUNT(*) FROM calendar_events WHERE calendar_id IN (" . implode(',', array_map('intval', $ids)) . ") GROUP BY calendar_id");
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$cid, $n]) $counts[(int)$cid] = (int)$n;
    }
    foreach ($cal->visibleCalendars() as $id => $c) {
        $p = $c['perms'];
        $share = [];
        if ($p['manage']) {
            foreach ((array)json_decode((string)($c['sharing_json'] ?? ''), true) as $s) {
                if (is_array($s) && (int)($s['user_id'] ?? 0) > 0) $share[] = ['user_id' => (int)$s['user_id'], 'role' => ($s['role'] ?? 'view') === 'edit' ? 'edit' : 'view'];
            }
        }
        $out[] = [
            'id'          => (int)$id,
            'name'        => $c['name'],
            'description' => (string)($c['description'] ?? ''),
            'color'       => $c['color'] ?: '#7a1f30',
            'type'        => $c['calendar_type'],
            'owner'       => trim(($c['owner_first'] ?? '') . ' ' . ($c['owner_last'] ?? '')),
            'mine'        => $p['mine'],
            'can_add'     => $p['add'],
            'can_manage'  => $p['manage'],
            'shared_with_me' => $p['share'] !== null,
            'share'       => $share,
            'events'      => $counts[(int)$id] ?? 0,
            'feed_url'    => ($p['manage'] && $c['ics_token']) ? cal_feed_url($c['ics_token']) : null,
        ];
    }
    return $out;
}

function cal_feed_url(string $token): string
{
    $base = defined('APP_BASE_URL') ? rtrim(APP_BASE_URL, '/') : ((!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return $base . '/calendar/ics.php?token=' . rawurlencode($token);
}
