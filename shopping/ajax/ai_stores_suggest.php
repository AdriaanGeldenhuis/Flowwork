<?php
// /shopping/ajax/ai_stores_suggest.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$listId = intval($input['list_id'] ?? 0);
$itemId = intval($input['item_id'] ?? 0);

if (!$listId || !$itemId) {
    echo json_encode(['ok' => false, 'error' => 'Missing IDs']);
    exit;
}

// Get item details
$stmt = $DB->prepare("
    SELECT i.*, l.company_id
    FROM shopping_items i
    JOIN shopping_lists l ON i.list_id = l.id
    WHERE i.id = ? AND l.company_id = ?
");
$stmt->execute([$itemId, $companyId]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    echo json_encode(['ok' => false, 'error' => 'Item not found']);
    exit;
}

// Get user preferences
$stmt = $DB->prepare("SELECT * FROM shopping_preferences WHERE company_id = ? AND user_id = ?");
$stmt->execute([$companyId, $userId]);
$prefs = $stmt->fetch(PDO::FETCH_ASSOC);
$radiusKm = $prefs['default_radius_km'] ?? 25.00;

try {
    $candidates = [];
    
    // SOURCE 1: CRM Suppliers (preferred)
    $crmCandidates = searchCRM($DB, $companyId, $item, $radiusKm);
    $candidates = array_merge($candidates, $crmCandidates);
    
    // SOURCE 2: Shopping Stores (internal catalog)
    $storeCandidates = searchStores($DB, $companyId, $item, $radiusKm);
    $candidates = array_merge($candidates, $storeCandidates);
    
    // SOURCE 3: Price History (past purchases)
    $historyCandidates = searchHistory($DB, $companyId, $item);
    $candidates = array_merge($candidates, $historyCandidates);
    
    // SOURCE 4: OpenAI powered web search
    if (count($candidates) < 3) {
        $aiCandidates = searchWithOpenAI($item, $radiusKm);
        $candidates = array_merge($candidates, $aiCandidates);
    }
    
    // Score and rank
    $candidates = scoreAndRank($candidates, $item, $prefs);
    
    // Take top 5
    $candidates = array_slice($candidates, 0, 5);
    
    // Store candidates in DB
    if (!empty($candidates)) {
        storeCandidates($DB, $listId, $itemId, $candidates);
    }
    
    echo json_encode(['ok' => true, 'candidates' => $candidates]);
    
} catch (Exception $e) {
    error_log("AI stores suggest error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Search error: ' . $e->getMessage()]);
}

// ========== HELPER FUNCTIONS ==========

function searchCRM($DB, $companyId, $item, $radiusKm) {
    $nameNorm = $item['name_norm'];
    
    $stmt = $DB->prepare("
        SELECT 
            a.id,
            a.name,
            a.phone,
            a.email,
            a.website,
            a.preferred,
            'crm' as source
        FROM crm_accounts a
        WHERE a.company_id = ? 
          AND a.type = 'supplier'
          AND a.status = 'active'
        ORDER BY a.preferred DESC, a.name
        LIMIT 5
    ");
    $stmt->execute([$companyId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $candidates = [];
    foreach ($accounts as $acc) {
        $candidates[] = [
            'source' => 'crm',
            'store_id' => $acc['id'],
            'store_name' => $acc['name'],
            'phone' => $acc['phone'],
            'email' => $acc['email'],
            'website' => $acc['website'],
            'address' => null,
            'lat' => null,
            'lng' => null,
            'distance_km' => null,
            'est_price_cents' => null,
            'stock_confidence' => 0.70,
            'aisle' => null,
            'pickup' => 'unknown',
            'delivery_eta_mins' => null,
            'score' => $acc['preferred'] ? 0.90 : 0.70,
            'explanation' => '✅ Found in your CRM' . ($acc['preferred'] ? ' (preferred supplier)' : '')
        ];
    }
    
    return $candidates;
}

function searchStores($DB, $companyId, $item, $radiusKm) {
    $stmt = $DB->prepare("
        SELECT 
            id,
            name,
            phone,
            address,
            lat,
            lng,
            preferred
        FROM shopping_stores
        WHERE company_id = ?
        ORDER BY preferred DESC, name
        LIMIT 5
    ");
    $stmt->execute([$companyId]);
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $candidates = [];
    foreach ($stores as $store) {
        $candidates[] = [
            'source' => 'catalog',
            'store_id' => $store['id'],
            'store_name' => $store['name'],
            'phone' => $store['phone'],
            'address' => $store['address'],
            'lat' => $store['lat'],
            'lng' => $store['lng'],
            'distance_km' => null,
            'est_price_cents' => null,
            'stock_confidence' => 0.60,
            'aisle' => null,
            'pickup' => 'yes',
            'delivery_eta_mins' => null,
            'score' => $store['preferred'] ? 0.85 : 0.65,
            'explanation' => '📦 Store in your catalog' . ($store['preferred'] ? ' (preferred)' : '')
        ];
    }
    
    return $candidates;
}

function searchHistory($DB, $companyId, $item) {
    $fingerprint = md5($item['name_norm'] . $item['unit']);
    
    $stmt = $DB->prepare("
        SELECT 
            h.store_id,
            s.name as store_name,
            s.phone,
            AVG(h.price_cents) as avg_price,
            COUNT(*) as purchase_count
        FROM shopping_price_history h
        LEFT JOIN shopping_stores s ON h.store_id = s.id
        WHERE h.company_id = ?
          AND h.item_fingerprint = ?
        GROUP BY h.store_id
        ORDER BY purchase_count DESC
        LIMIT 3
    ");
    $stmt->execute([$companyId, $fingerprint]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $candidates = [];
    foreach ($history as $h) {
        if (!$h['store_name']) continue;
        
        $candidates[] = [
            'source' => 'history',
            'store_id' => $h['store_id'],
            'store_name' => $h['store_name'],
            'phone' => $h['phone'],
            'address' => null,
            'lat' => null,
            'lng' => null,
            'distance_km' => null,
            'est_price_cents' => intval($h['avg_price']),
            'stock_confidence' => 0.80,
            'aisle' => null,
            'pickup' => 'yes',
            'delivery_eta_mins' => null,
            'score' => 0.75,
            'explanation' => '🕒 Bought here ' . $h['purchase_count'] . 'x before (avg R' . number_format($h['avg_price']/100, 2) . ')'
        ];
    }
    
    return $candidates;
}

function searchWithOpenAI($item, $radiusKm) {
    $apiKey = 'OPENAI_KEY_REMOVED';
    
    $itemName = $item['name_raw'];
    $qty = $item['qty'];
    $unit = $item['unit'];
    
    $prompt = "Find 3-5 real stores in South Africa (Gauteng/Vanderbijlpark area preferred) where I can buy: {$qty} {$unit} of {$itemName}

Return ONLY valid JSON (no markdown, no extra text) in this exact format:
[
  {
    \"store_name\": \"Store Name\",
    \"phone\": \"0123456789\",
    \"address\": \"Full address\",
    \"est_price_cents\": 50000,
    \"explanation\": \"Why this store\"
  }
]

Requirements:
- Real stores only (Builders Warehouse, Cashbuild, Leroy Merlin, local suppliers)
- Include phone numbers if known
- Estimate price in cents (R500 = 50000 cents)
- Brief explanation
- Return valid JSON only";

    $data = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'system', 'content' => 'You are a South African shopping assistant. Return only valid JSON arrays.'],
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.7,
        'max_tokens' => 1000
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("OpenAI API error: HTTP $httpCode - $response");
        return [];
    }

    $result = json_decode($response, true);
    $content = $result['choices'][0]['message']['content'] ?? '';
    
    // Clean markdown if present
    $content = preg_replace('/```json\s*/', '', $content);
    $content = preg_replace('/```\s*$/', '', $content);
    $content = trim($content);
    
    $stores = json_decode($content, true);
    
    if (!is_array($stores)) {
        error_log("OpenAI returned invalid JSON: $content");
        return [];
    }

    $candidates = [];
    foreach ($stores as $store) {
        if (empty($store['store_name'])) continue;
        
        $candidates[] = [
            'source' => 'bing', // Use 'bing' as generic external source
            'store_id' => null,
            'store_name' => $store['store_name'] ?? 'Unknown Store',
            'phone' => $store['phone'] ?? null,
            'email' => null,
            'website' => null,
            'address' => $store['address'] ?? null,
            'lat' => null,
            'lng' => null,
            'distance_km' => null,
            'est_price_cents' => $store['est_price_cents'] ?? null,
            'stock_confidence' => 0.60,
            'aisle' => null,
            'pickup' => 'unknown',
            'delivery_eta_mins' => null,
            'score' => 0.70,
            'explanation' => '🤖 AI: ' . ($store['explanation'] ?? 'Found via web search')
        ];
    }
    
    return $candidates;
}

function scoreAndRank($candidates, $item, $prefs) {
    foreach ($candidates as &$c) {
        $score = $c['score'];
        
        // Price estimate bonus
        if ($c['est_price_cents']) {
            $score += 0.10;
        }
        
        // Distance penalty
        if ($c['distance_km']) {
            $distPenalty = floor($c['distance_km'] / 10) * 0.05;
            $score -= $distPenalty;
        }
        
        // Stock confidence bonus
        if ($c['stock_confidence'] >= 0.75) {
            $score += 0.10;
        }
        
        // CRM source bonus
        if ($c['source'] === 'crm') {
            $score += 0.05;
        }
        
        $c['score'] = max(0, min(1, $score));
    }
    
    usort($candidates, function($a, $b) {
        return $b['score'] <=> $a['score'];
    });
    
    return $candidates;
}

function storeCandidates($DB, $listId, $itemId, $candidates) {
    // Clear old candidates
    $stmt = $DB->prepare("DELETE FROM shopping_candidates WHERE list_id = ? AND item_id = ?");
    $stmt->execute([$listId, $itemId]);
    
    // Insert new
    $stmt = $DB->prepare("
        INSERT INTO shopping_candidates 
        (list_id, item_id, source, store_id, store_name, phone, address, lat, lng, 
         distance_km, est_price_cents, stock_confidence, aisle, pickup, delivery_eta_mins, 
         score, explanation)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($candidates as $c) {
        $stmt->execute([
            $listId,
            $itemId,
            $c['source'],
            $c['store_id'],
            $c['store_name'],
            $c['phone'],
            $c['address'],
            $c['lat'],
            $c['lng'],
            $c['distance_km'],
            $c['est_price_cents'],
            $c['stock_confidence'],
            $c['aisle'],
            $c['pickup'],
            $c['delivery_eta_mins'],
            $c['score'],
            $c['explanation']
        ]);
    }
}