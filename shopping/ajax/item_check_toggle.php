<?php
// /shopping/ajax/item_check_toggle.php — retired endpoint (Shopping now uses /shopping/api/items.php). Answers 410 Gone.
require_once __DIR__ . '/../lib/ShRetired.php';
ShRetired::gone('/shopping/api/items.php');
