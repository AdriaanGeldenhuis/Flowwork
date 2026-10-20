<?php
// /shopping/ajax/ai_stores_suggest.php — retired endpoint (Shopping now uses /shopping/api/stores.php). Answers 410 Gone.
require_once __DIR__ . '/../lib/ShRetired.php';
ShRetired::gone('/shopping/api/stores.php');
