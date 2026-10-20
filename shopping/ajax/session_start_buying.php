<?php
// /shopping/ajax/session_start_buying.php — retired endpoint (Shopping now uses /shopping/api/lists.php). Answers 410 Gone.
require_once __DIR__ . '/../lib/ShRetired.php';
ShRetired::gone('/shopping/api/lists.php');
