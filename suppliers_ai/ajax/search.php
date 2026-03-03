<?php
// /suppliers_ai/ajax/search.php - Multi-source supplier search engine
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../error_log.txt');

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Load AI settings
$stmt = $DB->prepare("SELECT * FROM ai_settings WHERE company_id = ?");
$stmt->execute([$companyId]);
$aiSettings = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$aiSettings) {
    echo json_encode(['ok' => false, 'error' => 'AI settings not configured. Please visit Settings first.']);
    exit;
}

$apiKeys = !empty($aiSettings['api_keys_json']) ? json_decode($aiSettings['api_keys_json'], true) : [];
$rules = !empty($aiSettings['rules_json']) ? json_decode($aiSettings['rules_json'], true) : [];
$perHourCap = intval($aiSettings['per_hour_cap'] ?? 20);

// Rate limit (from settings, not hardcoded)
$stmt = $DB->prepare("
    SELECT COUNT(*) as cnt
    FROM ai_queries
    WHERE company_id = ? AND user_id = ?
    AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
");
$stmt->execute([$companyId, $userId]);
$recentCount = $stmt->fetch()['cnt'] ?? 0;

if ($recentCount >= $perHourCap) {
    echo json_encode(['ok' => false, 'error' => 'Rate limit exceeded (' . $perHourCap . '/hour). Try again shortly.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$queryText = trim($input['query'] ?? '');

if (empty($queryText)) {
    echo json_encode(['ok' => false, 'error' => 'Query cannot be empty']);
    exit;
}

// Read filters from frontend
$filters = $input['filters'] ?? [];
$filterRadius = floatval($filters['radius'] ?? $aiSettings['default_radius_km'] ?? 25);
$filterMinScore = floatval($filters['minScore'] ?? $aiSettings['min_score_threshold'] ?? 0.55);
$filterCompliance = $filters['compliance'] ?? '';
$filterSource = $filters['source'] ?? '';

$startTime = microtime(true);

// Parse query with enhanced extraction
$parsed = parseQuery($queryText, $rules);

$location = $parsed['location'] ?? 'South Africa';
$categories = $parsed['categories'] ?? [];
// Use the raw query for external searches so specific terms like "19mm rock" are preserved
$category = !empty($categories) ? $categories[0] : $queryText;

try {
    $candidates = [];
    $seenKeys = []; // For deduplication by name/phone/email

    // SOURCE 1: CRM Suppliers (always first, unless source filter says otherwise)
    if ($filterSource === '' || $filterSource === 'crm') {
        $crmCandidates = searchCRM($DB, $companyId, $categories, $location, $rules, $seenKeys);
        $candidates = array_merge($candidates, $crmCandidates);
    }

    // SOURCE 2: Past Winners (from ai_candidates history)
    if ($filterSource === '' || $filterSource === 'history') {
        $historyCandidates = searchHistory($DB, $companyId, $categories, $seenKeys);
        $candidates = array_merge($candidates, $historyCandidates);
    }

    $sourcesTried = [];
    $sourceErrors = [];

    // SOURCE 3: Google Places API (if key configured and not CRM-only)
    if ($filterSource !== 'crm' && $filterSource !== 'history') {
        $googlePlacesKey = $apiKeys['google_places_key'] ?? '';
        if (!empty($googlePlacesKey)) {
            $sourcesTried[] = 'google_places';
            $placesCandidates = searchGooglePlaces($googlePlacesKey, $category, $location, $seenKeys);
            $candidates = array_merge($candidates, $placesCandidates);
        }
    }

    // SOURCE 4: OpenAI — primary AI search for finding real suppliers
    if ($filterSource !== 'crm' && $filterSource !== 'history') {
        $openaiKey = $apiKeys['openai_api_key'] ?? '';
        $openaiEnabled = $apiKeys['openai_enabled'] ?? 0;
        $openaiModel = $apiKeys['openai_model'] ?? 'gpt-4o-mini';
        $openaiMaxTokens = intval($apiKeys['openai_max_tokens'] ?? 500);

        if (!$openaiEnabled) {
            $sourceErrors[] = 'OpenAI is disabled. Enable it in Settings → OpenAI Configuration.';
        } elseif (empty($openaiKey) || $openaiKey === 'OPENAI_KEY_REMOVED') {
            $sourceErrors[] = 'OpenAI API key not set. Add your key in Settings → OpenAI Configuration.';
        } else {
            $sourcesTried[] = 'openai';
            $openaiResult = searchWithOpenAI($openaiKey, $openaiModel, $openaiMaxTokens, $queryText, $location, $categories, $seenKeys);
            if (is_array($openaiResult) && isset($openaiResult['error'])) {
                $sourceErrors[] = 'OpenAI error: ' . $openaiResult['error'];
            } elseif (is_array($openaiResult)) {
                $candidates = array_merge($candidates, $openaiResult);
            }
        }
    }

    // If no candidates found and there were errors, report them
    if (empty($candidates) && !empty($sourceErrors)) {
        $tookMs = round((microtime(true) - $startTime) * 1000);
        echo json_encode([
            'ok' => false,
            'error' => $sourceErrors[0],
            'took_ms' => $tookMs
        ]);
        exit;
    }

    // Score, rank and filter
    $candidates = scoreAndRank($candidates, $filterMinScore, $filterCompliance, $rules);

    // Limit to top 10
    $candidates = array_slice($candidates, 0, 10);

    if (empty($candidates)) {
        // Still return ok:true with empty array so the UI can show "no results" properly
        $stmt = $DB->prepare("
            INSERT INTO ai_queries (company_id, user_id, q_text, parsed_json, took_ms, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $tookMs = round((microtime(true) - $startTime) * 1000);
        $stmt->execute([$companyId, $userId, $queryText, json_encode($parsed), $tookMs]);

        echo json_encode([
            'ok' => true,
            'query_id' => $DB->lastInsertId(),
            'candidates' => [],
            'took_ms' => $tookMs,
            'parsed' => $parsed,
            'source' => 'multi',
            'sources_tried' => $sourcesTried
        ]);
        exit;
    }

    // Save query to DB
    $stmt = $DB->prepare("
        INSERT INTO ai_queries (company_id, user_id, q_text, parsed_json, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$companyId, $userId, $queryText, json_encode($parsed)]);
    $queryId = $DB->lastInsertId();

    // Save candidates to DB and collect real IDs
    $insertStmt = $DB->prepare("
        INSERT INTO ai_candidates (
            company_id, query_id, account_id, rank, name, phone, email, website, address,
            categories_json, compliance_state, performance_json, score_final, explanation, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $savedCandidates = [];
    $rank = 1;
    foreach ($candidates as $c) {
        $insertStmt->execute([
            $companyId,
            $queryId,
            $c['account_id'] ?? null,
            $rank,
            $c['name'],
            $c['phone'] ?? null,
            $c['email'] ?? null,
            $c['website'] ?? null,
            $c['address'] ?? null,
            json_encode($c['categories'] ?? $categories),
            $c['compliance_state'] ?? 'missing',
            json_encode($c['performance'] ?? []),
            $c['score_final'],
            $c['explanation'] ?? ''
        ]);

        // Use the DB auto-increment ID (not uniqid)
        $dbId = $DB->lastInsertId();

        $savedCandidates[] = [
            'id' => intval($dbId),
            'rank' => $rank,
            'name' => $c['name'],
            'phone' => $c['phone'] ?? null,
            'email' => $c['email'] ?? null,
            'website' => $c['website'] ?? null,
            'address' => $c['address'] ?? null,
            'categories' => json_encode($c['categories'] ?? $categories),
            'compliance_state' => $c['compliance_state'] ?? 'missing',
            'performance' => json_encode($c['performance'] ?? []),
            'score_final' => $c['score_final'],
            'explanation' => $c['explanation'] ?? '',
            'account_id' => $c['account_id'] ?? null,
            'source' => $c['source'] ?? 'unknown'
        ];

        $rank++;
    }

    $tookMs = round((microtime(true) - $startTime) * 1000);
    $stmt = $DB->prepare("UPDATE ai_queries SET took_ms = ? WHERE id = ?");
    $stmt->execute([$tookMs, $queryId]);

    echo json_encode([
        'ok' => true,
        'query_id' => $queryId,
        'candidates' => $savedCandidates,
        'took_ms' => $tookMs,
        'parsed' => $parsed,
        'source' => 'multi',
        'sources_tried' => $sourcesTried
    ]);

} catch (Exception $e) {
    error_log("Supplier AI search error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Search error: ' . $e->getMessage()]);
}


// ========== SOURCE FUNCTIONS ==========

function searchCRM($DB, $companyId, $categories, $location, $rules, &$seenKeys) {
    // If no categories detected, skip CRM — we can't meaningfully filter
    if (empty($categories)) return [];

    // Build category filter via industry matching
    $industryFilter = '';
    $params = [$companyId];

    // Match CRM industries using detected categories
    $industryKeywords = [];
    foreach ($categories as $cat) {
        $industryKeywords[] = '%' . $cat . '%';
        // Add synonyms from rules if available
        $synonyms = $rules['synonyms'][$cat] ?? [];
        foreach ($synonyms as $syn) {
            $industryKeywords[] = '%' . $syn . '%';
        }
    }

    // Search by industry name or account name/notes matching category keywords
    $nameLikes = array_map(function() { return 'a.name LIKE ?'; }, $industryKeywords);
    $notesLikes = array_map(function() { return 'a.notes LIKE ?'; }, $industryKeywords);
    $industryLikes = array_map(function() { return 'ind.name LIKE ?'; }, $industryKeywords);

    $industryFilter = ' AND (' . implode(' OR ', array_merge($nameLikes, $notesLikes, $industryLikes)) . ')';
    $params = array_merge($params, $industryKeywords, $industryKeywords, $industryKeywords);

    $sql = "
        SELECT
            a.id,
            a.name,
            a.phone,
            a.email,
            a.website,
            a.preferred,
            a.on_time_percent,
            a.avg_response_hours,
            a.defect_rate_percent,
            a.notes,
            ind.name as industry_name,
            CONCAT_WS(', ', addr.line1, addr.line2, addr.city, addr.region, addr.postal_code) as address,
            addr.city
        FROM crm_accounts a
        LEFT JOIN crm_industries ind ON a.industry_id = ind.id
        LEFT JOIN crm_addresses addr ON addr.account_id = a.id AND addr.company_id = a.company_id
        WHERE a.company_id = ?
          AND a.type = 'supplier'
          AND a.status = 'active'
          {$industryFilter}
        GROUP BY a.id
        ORDER BY a.preferred DESC, a.name
        LIMIT 15
    ";

    $stmt = $DB->prepare($sql);
    $stmt->execute($params);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $candidates = [];
    foreach ($accounts as $acc) {
        $dedupKey = strtolower(trim($acc['name']));
        if (isset($seenKeys[$dedupKey])) continue;
        $seenKeys[$dedupKey] = true;
        if ($acc['phone']) $seenKeys[preg_replace('/[^0-9]/', '', $acc['phone'])] = true;
        if ($acc['email']) $seenKeys[strtolower($acc['email'])] = true;

        // Check compliance state from crm_compliance_docs
        $complianceState = getComplianceState($DB, $acc['id']);

        // Build performance metrics
        $performance = [];
        if ($acc['on_time_percent'] !== null) $performance['on_time_pct'] = floatval($acc['on_time_percent']);
        if ($acc['avg_response_hours'] !== null) $performance['response_hours'] = floatval($acc['avg_response_hours']);
        if ($acc['defect_rate_percent'] !== null) $performance['defect_rate'] = floatval($acc['defect_rate_percent']);

        // Base score for CRM suppliers
        $score = 0.80;
        if ($acc['preferred']) $score += 0.10;

        $explanation = 'Found in your CRM';
        if ($acc['preferred']) $explanation .= ' (preferred supplier)';
        if ($acc['industry_name']) $explanation .= ' - ' . $acc['industry_name'];

        $candidates[] = [
            'source' => 'crm',
            'account_id' => intval($acc['id']),
            'name' => $acc['name'],
            'phone' => $acc['phone'],
            'email' => $acc['email'],
            'website' => $acc['website'],
            'address' => $acc['address'],
            'categories' => $categories,
            'compliance_state' => $complianceState,
            'performance' => $performance,
            'score_final' => $score,
            'explanation' => $explanation
        ];
    }

    return $candidates;
}

function searchHistory($DB, $companyId, $categories, &$seenKeys) {
    if (empty($categories)) return [];

    $likeClauses = [];
    $params = [$companyId];
    foreach ($categories as $cat) {
        $likeClauses[] = 'ac.categories_json LIKE ?';
        $params[] = '%' . $cat . '%';
    }
    $whereCategories = implode(' OR ', $likeClauses);

    $stmt = $DB->prepare("
        SELECT
            ac.name, ac.phone, ac.email, ac.website, ac.address,
            ac.account_id, ac.score_final, ac.categories_json,
            COUNT(DISTINCT aa.id) as action_count
        FROM ai_candidates ac
        LEFT JOIN ai_actions aa ON aa.candidate_id = ac.id AND aa.company_id = ac.company_id
        WHERE ac.company_id = ? AND ({$whereCategories})
        GROUP BY ac.name
        ORDER BY action_count DESC, ac.score_final DESC
        LIMIT 5
    ");
    $stmt->execute($params);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $candidates = [];
    foreach ($history as $h) {
        if (empty($h['name'])) continue;

        $dedupKey = strtolower(trim($h['name']));
        if (isset($seenKeys[$dedupKey])) continue;
        if ($h['phone'] && isset($seenKeys[preg_replace('/[^0-9]/', '', $h['phone'])])) continue;
        if ($h['email'] && isset($seenKeys[strtolower($h['email'])])) continue;

        $seenKeys[$dedupKey] = true;
        if ($h['phone']) $seenKeys[preg_replace('/[^0-9]/', '', $h['phone'])] = true;
        if ($h['email']) $seenKeys[strtolower($h['email'])] = true;

        $actionCount = intval($h['action_count']);
        $explanation = 'Previously found supplier';
        if ($actionCount > 0) $explanation .= ' (' . $actionCount . ' interactions)';

        $candidates[] = [
            'source' => 'history',
            'account_id' => $h['account_id'] ? intval($h['account_id']) : null,
            'name' => $h['name'],
            'phone' => $h['phone'],
            'email' => $h['email'],
            'website' => $h['website'],
            'address' => $h['address'],
            'categories' => $categories,
            'compliance_state' => $h['account_id'] ? getComplianceState($GLOBALS['DB'] ?? null, $h['account_id']) : 'missing',
            'performance' => [],
            'score_final' => 0.70,
            'explanation' => $explanation
        ];
    }

    return $candidates;
}

function searchGooglePlaces($apiKey, $category, $location, &$seenKeys) {
    $query = urlencode($category . ' in ' . $location . ', South Africa');
    $url = 'https://maps.googleapis.com/maps/api/place/textsearch/json?query=' . $query . '&key=' . $apiKey . '&region=za';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("Google Places API error: HTTP $httpCode");
        return [];
    }

    $data = json_decode($response, true);
    $results = $data['results'] ?? [];

    $candidates = [];
    foreach (array_slice($results, 0, 5) as $place) {
        $name = $place['name'] ?? '';
        if (empty($name)) continue;

        $dedupKey = strtolower(trim($name));
        if (isset($seenKeys[$dedupKey])) continue;
        $seenKeys[$dedupKey] = true;

        $rating = floatval($place['rating'] ?? 0);
        $score = 0.65;
        if ($rating >= 4.5) $score = 0.80;
        elseif ($rating >= 4.0) $score = 0.75;
        elseif ($rating >= 3.5) $score = 0.70;

        $explanation = 'Found via Google Places';
        if ($rating > 0) $explanation .= ' (rated ' . number_format($rating, 1) . '/5)';

        $candidates[] = [
            'source' => 'places',
            'account_id' => null,
            'name' => $name,
            'phone' => null,
            'email' => null,
            'website' => null,
            'address' => $place['formatted_address'] ?? null,
            'categories' => [$category],
            'compliance_state' => 'missing',
            'performance' => [],
            'score_final' => $score,
            'explanation' => $explanation
        ];
    }

    return $candidates;
}

function searchWithOpenAI($apiKey, $model, $maxTokens, $queryText, $location, $categories, &$seenKeys) {
    $categoryHint = !empty($categories) ? implode(', ', $categories) : 'general';
    $locationHint = !empty($location) ? $location : 'South Africa';

    $searchPrompt = <<<PROMPT
You are a supplier/subcontractor directory assistant for South Africa.
A user searched: "{$queryText}"
Detected location: {$locationHint}
Detected categories: {$categoryHint}

Find REAL suppliers, subcontractors, or companies that match this search.

RULES:
1. Find companies that actually supply or do this kind of work in or near {$locationHint}
2. Include well-known chains, franchises, or established local businesses
3. Return 5-8 companies (quality over quantity)
4. Phone numbers in SA format (e.g. 016 xxx xxxx for Vaal area, 011 for JHB, etc.)
5. If you only know 2-3 real companies, return 2-3 (not fake ones)
6. Include both large suppliers AND smaller local subcontractors if relevant

Return ONLY this JSON (no explanations):
{
  "companies": [
    {
      "name": "Company name",
      "phone": "Phone number",
      "email": "Email if known or null",
      "website": "Website if known or null",
      "address": "Physical address or area",
      "confidence": "high|medium"
    }
  ]
}

If you cannot find any companies, return: {"companies": []}
PROMPT;

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a South African supplier and subcontractor directory. Find real businesses that match the search query. Include well-known chains, local businesses, and specialists in the area.'],
                ['role' => 'user', 'content' => $searchPrompt]
            ],
            'max_tokens' => max(500, $maxTokens),
            'temperature' => 0.1
        ])
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        error_log("OpenAI curl error: " . $curlError);
        return ['error' => 'Connection failed: ' . $curlError];
    }

    if ($httpCode !== 200) {
        error_log("OpenAI API error: HTTP $httpCode - " . substr($response, 0, 500));
        $errData = json_decode($response, true);
        $errMsg = $errData['error']['message'] ?? "HTTP $httpCode";
        if ($httpCode === 401) $errMsg = 'Invalid API key. Check your OpenAI key in Settings.';
        if ($httpCode === 429) $errMsg = 'OpenAI rate limit exceeded. Try again in a minute.';
        if ($httpCode === 402) $errMsg = 'OpenAI billing issue. Check your OpenAI account has credits.';
        return ['error' => $errMsg];
    }

    $data = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? '';

    // Clean markdown code blocks
    $content = preg_replace('/```json\s*/', '', $content);
    $content = preg_replace('/```\s*/', '', $content);
    $content = trim($content);

    $result = json_decode($content, true);
    if (!is_array($result)) {
        error_log("OpenAI response not valid JSON: " . substr($content, 0, 500));
        return ['error' => 'Could not parse AI response'];
    }
    $suppliers = $result['companies'] ?? [];

    $candidates = [];
    foreach ($suppliers as $s) {
        if (empty($s['name'])) continue;

        $dedupKey = strtolower(trim($s['name']));
        if (isset($seenKeys[$dedupKey])) continue;
        if (!empty($s['phone']) && isset($seenKeys[preg_replace('/[^0-9]/', '', $s['phone'])])) continue;

        $seenKeys[$dedupKey] = true;
        if (!empty($s['phone'])) $seenKeys[preg_replace('/[^0-9]/', '', $s['phone'])] = true;
        if (!empty($s['email'])) $seenKeys[strtolower($s['email'])] = true;

        $candidates[] = [
            'source' => 'openai',
            'account_id' => null,
            'name' => $s['name'],
            'phone' => $s['phone'] ?? null,
            'email' => $s['email'] ?? null,
            'website' => $s['website'] ?? null,
            'address' => $s['address'] ?? null,
            'categories' => $categories,
            'compliance_state' => 'missing',
            'performance' => [],
            'score_final' => (($s['confidence'] ?? 'medium') === 'high') ? 0.75 : 0.65,
            'explanation' => 'Found via AI search'
        ];
    }

    return $candidates;
}


// ========== SCORING & RANKING ==========

function scoreAndRank($candidates, $minScore, $complianceFilter, $rules) {
    $denyPhones = $rules['deny_list']['phones'] ?? [];
    $denyDomains = $rules['deny_list']['domains'] ?? [];
    $allowPhones = $rules['allow_list']['phones'] ?? [];

    foreach ($candidates as $key => &$c) {
        $score = $c['score_final'];

        // Source bonuses
        if ($c['source'] === 'crm') $score += 0.05;

        // Performance bonuses
        $perf = $c['performance'] ?? [];
        if (!empty($perf['on_time_pct']) && $perf['on_time_pct'] >= 90) $score += 0.05;
        if (!empty($perf['defect_rate']) && $perf['defect_rate'] <= 2) $score += 0.03;

        // Compliance bonuses/penalties
        if ($c['compliance_state'] === 'valid') $score += 0.05;
        if ($c['compliance_state'] === 'expired') $score -= 0.10;

        // Allow-list bonus
        $phone = preg_replace('/[^0-9]/', '', $c['phone'] ?? '');
        if (!empty($allowPhones) && in_array($phone, $allowPhones)) $score += 0.15;

        // Deny-list: remove entirely
        if (!empty($denyPhones) && in_array($phone, $denyPhones)) {
            unset($candidates[$key]);
            continue;
        }
        if (!empty($denyDomains) && !empty($c['email'])) {
            $domain = substr($c['email'], strpos($c['email'], '@') + 1);
            if (in_array($domain, $denyDomains)) {
                unset($candidates[$key]);
                continue;
            }
        }

        $c['score_final'] = max(0, min(1, round($score, 4)));
    }
    unset($c);

    $candidates = array_values($candidates);

    // Filter by minimum score
    $candidates = array_filter($candidates, function($c) use ($minScore) {
        return $c['score_final'] >= $minScore;
    });

    // Filter by compliance if set
    if (!empty($complianceFilter)) {
        $candidates = array_filter($candidates, function($c) use ($complianceFilter) {
            return $c['compliance_state'] === $complianceFilter;
        });
    }

    $candidates = array_values($candidates);

    // Sort by score descending
    usort($candidates, function($a, $b) {
        return $b['score_final'] <=> $a['score_final'];
    });

    return $candidates;
}


// ========== COMPLIANCE CHECK ==========

function getComplianceState($DB, $accountId) {
    if (!$DB || !$accountId) return 'missing';

    $stmt = $DB->prepare("
        SELECT expiry_date, status
        FROM crm_compliance_docs
        WHERE account_id = ?
        ORDER BY expiry_date DESC
        LIMIT 1
    ");
    $stmt->execute([$accountId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) return 'missing';

    // Use status if set
    if (!empty($doc['status']) && in_array($doc['status'], ['valid', 'expiring', 'expired', 'missing'])) {
        return $doc['status'];
    }

    // Fall back to date-based check
    if (empty($doc['expiry_date'])) return 'missing';

    $expiry = strtotime($doc['expiry_date']);
    $now = time();
    $thirtyDays = 30 * 24 * 60 * 60;

    if ($expiry < $now) return 'expired';
    if ($expiry < ($now + $thirtyDays)) return 'expiring';
    return 'valid';
}


// ========== QUERY PARSING ==========

function parseQuery($text, $rules = []) {
    $categories = [];
    $location = null;
    $budget = null;
    $quantity = null;
    $urgency = null;

    // Extract location (e.g., "in Johannesburg", "near Sandton", "at Midrand")
    if (preg_match('/\b(?:in|near|at|around)\s+([A-Za-z\s]+?)(?:\s|$|,|\bfor\b|\bunder\b|\bbudget\b)/i', $text, $match)) {
        $location = trim($match[1]);
    }

    // Extract budget (e.g., "under R5k", "R5000", "budget R5000", "less than R10k")
    if (preg_match('/(?:under|budget|less than|max|below)\s*R?\s*(\d+\.?\d*)\s*(k|K)?/i', $text, $match)) {
        $amount = floatval($match[1]);
        if (!empty($match[2])) $amount *= 1000;
        $budget = $amount;
    } elseif (preg_match('/R\s*(\d+\.?\d*)\s*(k|K)?/i', $text, $match)) {
        $amount = floatval($match[1]);
        if (!empty($match[2])) $amount *= 1000;
        $budget = $amount;
    }

    // Extract quantity (e.g., "need 3", "2 plumbers", "5 contractors")
    if (preg_match('/\b(\d{1,3})\s+(?:plumber|electrician|contractor|supplier|painter|roofer|builder)/i', $text, $match)) {
        $quantity = intval($match[1]);
    } elseif (preg_match('/\bneed\s+(\d{1,3})\b/i', $text, $match)) {
        $quantity = intval($match[1]);
    }

    // Extract urgency/dates
    $lowerText = strtolower($text);
    if (preg_match('/\b(urgent|asap|emergency|immediately)\b/i', $text)) {
        $urgency = 'urgent';
    } elseif (preg_match('/\b(tomorrow)\b/i', $text)) {
        $urgency = 'tomorrow';
    } elseif (preg_match('/\b(next week)\b/i', $text)) {
        $urgency = 'next_week';
    } elseif (preg_match('/\b(this week)\b/i', $text)) {
        $urgency = 'this_week';
    }

    // Extract categories - base map + rules synonyms
    $map = [
        'plumber' => ['plumb', 'geyser', 'pipe', 'drain', 'water heater', 'tap', 'leak'],
        'electrician' => ['electric', 'wiring', 'coc', 'circuit', 'breaker', 'db board'],
        'contractor' => ['contractor', 'builder', 'subcontractor', 'construction'],
        'hardware' => ['hardware', 'building supplies', 'tools', 'materials'],
        'roofing' => ['roof', 'sheet', 'tile', 'gutters', 'fascia'],
        'painting' => ['paint', 'coating', 'primer', 'spray'],
        'hvac' => ['hvac', 'aircon', 'air conditioning', 'heating', 'ventilation'],
        'security' => ['security', 'alarm', 'cctv', 'camera', 'access control'],
        'landscaping' => ['garden', 'landscap', 'irrigation', 'lawn'],
        'cleaning' => ['clean', 'janitorial', 'sanitation', 'waste'],
        'transport' => ['transport', 'logistics', 'delivery', 'courier', 'trucking'],
        'catering' => ['cater', 'food', 'kitchen', 'chef'],
        'it' => ['it ', 'computer', 'software', 'network', 'server'],
        'welding' => ['weld', 'fabricat', 'steel', 'metal work'],
        'flooring' => ['floor', 'carpet', 'vinyl', 'laminate', 'tiling'],
        'aggregates' => ['rock', 'stone', 'aggregate', 'gravel', 'sand', 'crusher', 'quarry', 'crushed stone', 'building sand', 'river sand', 'fill'],
        'concrete' => ['concrete', 'cement', 'ready-mix', 'readymix', 'ready mix', 'screed', 'mortar'],
        'bricks' => ['brick', 'block', 'paver', 'maxi brick', 'stock brick', 'cement block'],
        'timber' => ['timber', 'lumber', 'wood', 'planks', 'poles', 'shutterboard'],
        'plumbing_supplies' => ['pvc pipe', 'fitting', 'valve', 'cistern', 'toilet', 'basin', 'bath'],
        'earthworks' => ['earthwork', 'excavat', 'tipper', 'tlb', 'bobcat', 'bulldozer', 'plant hire']
    ];

    // Merge synonyms from rules
    $rulesSynonyms = $rules['synonyms'] ?? [];
    foreach ($rulesSynonyms as $key => $syns) {
        if (isset($map[$key])) {
            $map[$key] = array_unique(array_merge($map[$key], $syns));
        } else {
            $map[$key] = $syns;
        }
    }

    // Merge categories from rules
    $rulesCategories = $rules['categories'] ?? [];
    foreach ($rulesCategories as $key => $keywords) {
        if (isset($map[$key])) {
            $map[$key] = array_unique(array_merge($map[$key], $keywords));
        } else {
            $map[$key] = $keywords;
        }
    }

    foreach ($map as $cat => $keywords) {
        foreach ($keywords as $kw) {
            if (stripos($lowerText, strtolower($kw)) !== false) {
                $categories[] = $cat;
                break;
            }
        }
    }

    return [
        'categories' => array_values(array_unique($categories)),
        'location' => $location,
        'budget' => $budget,
        'quantity' => $quantity,
        'urgency' => $urgency
    ];
}
