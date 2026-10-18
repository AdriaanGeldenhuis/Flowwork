<?php
// /projects/api/mywork.php — every open item the user is on, across all boards.
//   GET [?done=1]
require_once __DIR__ . '/_bootstrap.php';

pr_json(['items' => ProjectLink::myWork($PR, 600, !empty($_GET['done'])), 'today' => ProjectRepo::today()]);
