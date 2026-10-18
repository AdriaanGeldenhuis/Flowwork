<?php
// /projects/api/people.php — active people in the company (pickers, @mentions).
require_once __DIR__ . '/_bootstrap.php';

pr_json(['people' => array_values($PR->people())]);
