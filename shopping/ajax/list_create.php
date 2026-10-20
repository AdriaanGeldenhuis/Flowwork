<?php
// /shopping/ajax/list_create.php — retired endpoint (Shopping now uses /shopping/api/lists.php). Answers 410 Gone.
require_once __DIR__ . '/../lib/ShRetired.php';
ShRetired::gone('/shopping/api/lists.php');
