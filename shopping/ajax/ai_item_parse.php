<?php
// /shopping/ajax/ai_item_parse.php — retired endpoint (Shopping now uses /shopping/api/items.php). Answers 410 Gone.
require_once __DIR__ . '/../lib/ShRetired.php';
ShRetired::gone('/shopping/api/items.php');
