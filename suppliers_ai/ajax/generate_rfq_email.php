<?php
// /suppliers_ai/ajax/generate_rfq_email.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$supplierName = $input['supplier_name'] ?? '';
$supplierEmail = $input['supplier_email'] ?? '';
$queryText = $input['query_text'] ?? '';
$scope = $input['scope'] ?? '';
$deadline = $input['deadline'] ?? date('Y-m-d', strtotime('+7 days'));

if (empty($supplierName) || empty($queryText)) {
    echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
    exit;
}

// Get company info
$stmt = $DB->prepare("SELECT name, email, phone FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();

// Get user info
$stmt = $DB->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$userInfo = $stmt->fetch();
$userName = $userInfo['first_name'] . ' ' . $userInfo['last_name'];
$userEmail = $userInfo['email'];

// Get OpenAI key
$stmt = $DB->prepare("SELECT api_keys_json FROM ai_settings WHERE company_id = ?");
$stmt->execute([$companyId]);
$settings = $stmt->fetch();
$apiKeys = json_decode($settings['api_keys_json'] ?? '{}', true);

$apiKey = $apiKeys['openai_api_key'] ?? '';
$model = $apiKeys['openai_model'] ?? 'gpt-4o-mini';
$enabled = !empty($apiKeys['openai_enabled']);

if (!$enabled || empty($apiKey)) {
    // Fallback to basic template
    $emailBody = generateBasicRFQEmail($company, $userInfo, $supplierName, $queryText, $scope, $deadline);
    echo json_encode([
        'ok' => true,
        'subject' => "RFQ: {$queryText} - {$company['name']}",
        'body' => $emailBody,
        'method' => 'template'
    ]);
    exit;
}

// Generate email with OpenAI
$prompt = <<<PROMPT
Write a professional RFQ (Request for Quote) email in South African English.

Company Details:
- Name: {$company['name']}
- Contact Email: {$company['email']}
- Contact Phone: {$company['phone']}

Recipient: $supplierName

User Sending: $userName ($userEmail)

Original Search Query: "$queryText"

Scope of Work: $scope

Quote Deadline: $deadline

Requirements:
1. Professional greeting addressing the supplier
2. Brief introduction of {$company['name']}
3. Clear description of work needed (based on query)
4. Mention scope if provided
5. State deadline for quote submission
6. Request compliance documents if relevant (COC/BEE/Tax)
7. Provide contact details for questions
8. Professional sign-off with sender's name

Keep it concise (250-300 words), formal, clear, and actionable. Use proper South African business tone.
PROMPT;

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => 'You are a professional procurement officer in South Africa writing RFQ emails.'],
            ['role' => 'user', 'content' => $prompt]
        ],
        'max_tokens' => 600,
        'temperature' => 0.7
    ])
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    // Fallback
    $emailBody = generateBasicRFQEmail($company, $userInfo, $supplierName, $queryText, $scope, $deadline);
    echo json_encode([
        'ok' => true,
        'subject' => "RFQ: {$queryText} - {$company['name']}",
        'body' => $emailBody,
        'method' => 'fallback'
    ]);
    exit;
}

$data = json_decode($response, true);
$emailBody = $data['choices'][0]['message']['content'] ?? '';

echo json_encode([
    'ok' => true,
    'subject' => "RFQ: {$queryText} - {$company['name']}",
    'body' => $emailBody,
    'method' => 'openai'
]);

function generateBasicRFQEmail($company, $userInfo, $supplierName, $queryText, $scope, $deadline) {
    $userName = $userInfo['first_name'] . ' ' . $userInfo['last_name'];
    $userEmail = $userInfo['email'];
    
    return <<<EMAIL
Dear $supplierName,

We are seeking a quote for the following work:

**Project:** $queryText

**Scope:** $scope

**Quote Deadline:** $deadline

{$company['name']} requires this work to be completed to industry standards. Please include:
- Detailed breakdown of costs
- Timeline for completion
- Relevant certifications (COC/BEE/Tax Clearance if applicable)
- Terms and conditions

Should you have any questions, please contact me directly.

Kind regards,

$userName
{$company['name']}
Email: $userEmail
Phone: {$company['phone']}
EMAIL;
}