<?php
// /qi/ai/smart_terms.php
// Generate smart terms & conditions based on customer and risk profile

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$customerId  = isset($input['customer_id']) ? (int)$input['customer_id'] : 0;
$riskProfile = isset($input['risk_profile']) ? strtolower(trim($input['risk_profile'])) : 'medium';

$companyId = $_SESSION['company_id'] ?? 0;
$defaultTerms = '';
$defaultPaymentTerms = 30;
if ($companyId) {
    $stmt = $DB->prepare("SELECT default_terms, default_payment_terms FROM qi_settings WHERE company_id = ? LIMIT 1");
    $stmt->execute([$companyId]);
    $row = $stmt->fetch();
    if ($row) {
        $defaultTerms = $row['default_terms'] ?? '';
        if (!empty($row['default_payment_terms']) && is_numeric($row['default_payment_terms'])) {
            $defaultPaymentTerms = (int)$row['default_payment_terms'];
        }
    }
}

// Base terms from defaults or fallback
$terms = $defaultTerms;
if (!$terms) {
    $terms = "Payment is due within {$defaultPaymentTerms} days from invoice date.";
}

// Append risk-based clauses
switch ($riskProfile) {
    case 'high':
        $terms .= "\n\nA 50% deposit is required before work commences. Remaining balance due upon completion. Late payments may incur interest charges.";
        break;
    case 'low':
        $terms .= "\n\nThank you for your business! We appreciate timely payment. If you have any questions, please contact us.";
        break;
    default:
        // medium or unknown
        $terms .= "\n\nA 30% deposit may be requested based on project scope. Please settle the balance promptly to avoid delays.";
        break;
}

// Always include general clause
$terms .= "\n\nOwnership of deliverables remains with the company until full payment is received. These terms shall be governed by local laws.";

echo json_encode(['ok' => true, 'data' => ['terms' => $terms]]);
exit;