<?php
// /suppliers_ai/ajax/test_openai.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Check admin
$stmt = $DB->prepare("SELECT role FROM users WHERE id = ? AND company_id = ?");
$stmt->execute([$userId, $companyId]);
$user = $stmt->fetch();
if (!in_array($user['role'] ?? '', ['admin'])) {
    echo json_encode(['ok' => false, 'error' => 'Admin only']);
    exit;
}

// Get API key
$stmt = $DB->prepare("SELECT api_keys_json FROM ai_settings WHERE company_id = ?");
$stmt->execute([$companyId]);
$settings = $stmt->fetch();
$apiKeys = json_decode($settings['api_keys_json'] ?? '{}', true);

$apiKey = $apiKeys['openai_api_key'] ?? '';
$model = $apiKeys['openai_model'] ?? 'gpt-4o-mini';

if (empty($apiKey)) {
    echo json_encode(['ok' => false, 'error' => 'No API key configured']);
    exit;
}

// Test request
$startTime = microtime(true);

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
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'Say "OpenAI connection successful" in Afrikaans.']
        ],
        'max_tokens' => 50
    ])
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$latency = round((microtime(true) - $startTime) * 1000);
curl_close($ch);

if ($httpCode !== 200) {
    $error = json_decode($response, true);
    echo json_encode([
        'ok' => false, 
        'error' => $error['error']['message'] ?? 'HTTP ' . $httpCode,
        'raw_response' => $response
    ]);
    exit;
}

$data = json_decode($response, true);
$reply = $data['choices'][0]['message']['content'] ?? '';

// Log successful test
$stmt = $DB->prepare("
    INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
    VALUES (?, ?, 'ai_openai_test', ?, ?, NOW())
");
$stmt->execute([
    $companyId,
    $userId,
    json_encode(['model' => $model, 'latency_ms' => $latency]),
    $_SERVER['REMOTE_ADDR'] ?? null
]);

echo json_encode([
    'ok' => true,
    'model' => $model,
    'latency_ms' => $latency,
    'response' => $reply,
    'tokens_used' => $data['usage']['total_tokens'] ?? 0
]);