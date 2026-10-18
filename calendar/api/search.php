<?php
// GET /calendar/api/search.php?q=site — events matching the title, place or notes
// (upcoming first; a repeating event shows its next occurrence).
require_once __DIR__ . '/_bootstrap.php';

cal_rate_limit('cal-search', 120, 60);
cal_json(['results' => $CAL->search((string)($_GET['q'] ?? ''))]);
