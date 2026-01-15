<?php
// /qi/quote_edit.php - COMPLETE
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

$companyId = $_SESSION['company_id'];
$quoteId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$quoteId) {
    header('Location: /qi/');
    exit;
}

$stmt = $DB->prepare("SELECT id, status FROM quotes WHERE id = ? AND company_id = ?");
$stmt->execute([$quoteId, $companyId]);
$quote = $stmt->fetch();

if (!$quote) {
    die('Quote not found');
}

if ($quote['status'] !== 'draft') {
    die('Only draft quotes can be edited');
}

header('Location: /qi/quote_new.php?edit=' . $quoteId);
exit;