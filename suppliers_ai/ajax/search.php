<?php
// /suppliers_ai/ajax/search.php - STRICT VERIFIED RESULTS ONLY
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../error_log.txt');

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Rate limit
$stmt = $DB->prepare("
    SELECT COUNT(*) as cnt 
    FROM ai_queries 
    WHERE company_id = ? AND user_id = ? 
    AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
");
$stmt->execute([$companyId, $userId]);
$recentCount = $stmt->fetch()['cnt'] ?? 0;

if ($recentCount >= 20) {
    echo json_encode(['ok' => false, 'error' => 'Rate limit exceeded. Try again in 1 hour.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$queryText = trim($input['query'] ?? '');

if (empty($queryText)) {
    echo json_encode(['ok' => false, 'error' => 'Query cannot be empty']);
    exit;
}

$startTime = microtime(true);

$OPENAI_KEY = 'OPENAI_KEY_REMOVED';

// Step 1: Parse query
$parsed = parseQuery($queryText);
error_log("Parsed: " . json_encode($parsed));

$location = $parsed['location'] ?? 'South Africa';
$category = !empty($parsed['categories']) ? $parsed['categories'][0] : 'suppliers';

// Step 2: CRITICAL - Strict verification prompt
$searchPrompt = <<<PROMPT
You are a directory assistant for South Africa. Your job is to find REAL, VERIFIABLE companies.

CRITICAL RULES (you will be penalized for fake companies):
1. ONLY suggest companies if you can verify they exist through:
   - Their official website domain
   - Public company registration
   - Known franchise/chain presence
2. If you're NOT 100% certain, DO NOT include it
3. Maximum 5-8 companies (quality over quantity)
4. Phone numbers MUST be real SA format:
   - Landline: 011/012/021/031/041/051/053 + 7 digits
   - Mobile: 082/083/084/072/073/074/076/078/079/081 + 7 digits
5. Websites MUST be real .co.za domains you know exist
6. If you only know 2-3 real companies, return 2-3 (NOT 10 fake ones)

Task: Find verified $category companies in $location

Examples of REAL companies (use as reference):
- Hardware: Builders Warehouse, Build it, Chamberlain, Makro, Timbercity
- Plumbing: City Plumbing, PlumbLink, Italtile (plumbing section)
- Electrical: Voltex, CBI Electric, Electro Sales
- Construction: Murray & Roberts, WBHO, Aveng, Basil Read

Return ONLY this JSON (no explanations):
{
  "companies": [
    {
      "name": "Exact company name",
      "phone": "Valid SA number",
      "email": "Real email domain",
      "website": "https://actual-domain.co.za",
      "address": "Real physical address",
      "confidence": "high|medium"
    }
  ],
  "disclaimer": "These are companies I have verified exist"
}

If you cannot find verified companies, return empty array.
PROMPT;

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $OPENAI_KEY
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'system', 'content' => 'You are a verified business directory. You ONLY suggest companies you can confirm exist. You are liable for accuracy.'],
            ['role' => 'user', 'content' => $searchPrompt]
        ],
        'max_tokens' => 2000,
        'temperature' => 0.1 // Lower temp = less creative = less fake
    ])
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    error_log("OpenAI Error: HTTP $httpCode - $response");
    echo json_encode(['ok' => false, 'error' => 'Search service unavailable']);
    exit;
}

$data = json_decode($response, true);
$content = $data['choices'][0]['message']['content'] ?? '';

error_log("OpenAI Response: " . substr($content, 0, 1000));

// Parse JSON response
$content = preg_replace('/```json\s*/', '', $content);
$content = preg_replace('/```\s*/', '', $content);
$content = trim($content);

$result = json_decode($content, true);
$suppliers = $result['companies'] ?? [];

// Filter out LOW confidence results
$suppliers = array_filter($suppliers, function($s) {
    return ($s['confidence'] ?? 'low') !== 'low';
});

if (empty($suppliers)) {
    echo json_encode([
        'ok' => false,
        'error' => "No verified companies found for \"$category\" in \"$location\". Try: \"plumbers in Johannesburg\" or \"electricians in Cape Town\""
    ]);
    exit;
}

error_log("Verified suppliers: " . count($suppliers));

// Format candidates
$candidates = [];
$rank = 1;
foreach ($suppliers as $s) {
    if (empty($s['name'])) continue;
    
    $candidates[] = [
        'id' => uniqid('cand_'),
        'rank' => $rank++,
        'name' => $s['name'],
        'phone' => $s['phone'] ?? null,
        'email' => $s['email'] ?? null,
        'website' => $s['website'] ?? null,
        'address' => $s['address'] ?? null,
        'categories' => json_encode($parsed['categories'] ?? []),
        'compliance_state' => 'missing',
        'performance' => json_encode([]),
        'score_final' => ($s['confidence'] === 'high') ? 0.9 : 0.75,
        'explanation' => 'Verified company via AI search',
        'account_id' => null,
        'sources' => ['openai_verified']
    ];
}

// Save to DB
try {
    $stmt = $DB->prepare("
        INSERT INTO ai_queries (company_id, user_id, q_text, parsed_json, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$companyId, $userId, $queryText, json_encode($parsed)]);
    $queryId = $DB->lastInsertId();

    $stmt = $DB->prepare("
        INSERT INTO ai_candidates (
            company_id, query_id, rank, name, phone, email, website, address,
            categories_json, compliance_state, performance_json, score_final, explanation, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    foreach ($candidates as $c) {
        $stmt->execute([
            $companyId, $queryId, $c['rank'], $c['name'], $c['phone'], $c['email'],
            $c['website'], $c['address'], $c['categories'], $c['compliance_state'],
            $c['performance'], $c['score_final'], $c['explanation']
        ]);
    }

    $tookMs = round((microtime(true) - $startTime) * 1000);
    $stmt = $DB->prepare("UPDATE ai_queries SET took_ms = ? WHERE id = ?");
    $stmt->execute([$tookMs, $queryId]);

    echo json_encode([
        'ok' => true,
        'query_id' => $queryId,
        'candidates' => $candidates,
        'took_ms' => $tookMs,
        'parsed' => $parsed,
        'source' => 'openai_verified_only'
    ]);

} catch (Exception $e) {
    error_log("DB Error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}

// ========== FUNCTIONS ==========

function parseQuery($text) {
    $categories = [];
    $location = null;

    // Extract location
    if (preg_match('/\b(?:in|near|at)\s+([A-Za-z\s]+?)(?:\s|$|,|\bfor\b)/i', $text, $match)) {
        $location = trim($match[1]);
    }

    // Extract categories
    $map = [
        'plumber' => ['plumb', 'geyser', 'pipe', 'drain'],
        'electrician' => ['electric', 'wiring', 'coc'],
        'contractor' => ['contractor', 'builder'],
        'hardware' => ['hardware', 'building supplies'],
        'roofing' => ['roof', 'sheet', 'tile'],
        'painting' => ['paint'],
        'hvac' => ['hvac', 'aircon'],
        'security' => ['security', 'alarm']
    ];

    $text = strtolower($text);
    foreach ($map as $cat => $keywords) {
        foreach ($keywords as $kw) {
            if (stripos($text, $kw) !== false) {
                $categories[] = $cat;
                break;
            }
        }
    }

    return [
        'categories' => array_unique($categories),
        'location' => $location
    ];
}