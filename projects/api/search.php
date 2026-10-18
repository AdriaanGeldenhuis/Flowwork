<?php
// /projects/api/search.php — projects, boards and items matching ?q= (2+ characters).
require_once __DIR__ . '/_bootstrap.php';

pr_rate_limit('pr-search', 120, 60);
pr_json(ProjectLink::search($PR, mb_substr((string)($_GET['q'] ?? ''), 0, 200)));
