<?php
// /crm/ajax/dedupe_scan.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

// Disable buffering for streaming
ob_implicit_flush(true);
ob_end_flush();

header('Content-Type: application/json');
header('X-Accel-Buffering: no');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

function streamProgress($percent, $message) {
    echo json_encode(['progress' => $percent, 'message' => $message]) . "\n";
    flush();
}

function streamComplete($accountsScanned, $candidatesFound, $candidatesAdded) {
    echo json_encode([
        'complete' => true,
        'accounts_scanned' => $accountsScanned,
        'candidates_found' => $candidatesFound,
        'candidates_added' => $candidatesAdded
    ]) . "\n";
    flush();
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $type = $input['type'] ?? 'all';
    $clearExisting = $input['clear_existing'] ?? true;

    streamProgress(5, 'Initializing scan...');

    // Clear existing candidates if requested
    if ($clearExisting) {
        $stmt = $DB->prepare("
            DELETE FROM crm_merge_candidates 
            WHERE company_id = ? AND resolved = 0
        ");
        $stmt->execute([$companyId]);
        streamProgress(10, 'Cleared existing candidates...');
    }

    // Get duplicate threshold from settings
    $thresholdStmt = $DB->prepare("
        SELECT setting_value FROM company_settings 
        WHERE company_id = ? AND setting_key = 'crm_duplicate_threshold'
    ");
    $thresholdStmt->execute([$companyId]);
    $threshold = floatval($thresholdStmt->fetchColumn() ?: 0.85);

    streamProgress(15, 'Loading accounts...');

    // Fetch all accounts
    $sql = "SELECT id, name, email, phone, vat_no, reg_no FROM crm_accounts WHERE company_id = ?";
    $params = [$companyId];

    if ($type !== 'all') {
        $sql .= " AND type = ?";
        $params[] = $type;
    }

    $sql .= " ORDER BY id ASC";

    $stmt = $DB->prepare($sql);
    $stmt->execute($params);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalAccounts = count($accounts);
    $accountsScanned = 0;
    $candidatesFound = 0;
    $candidatesAdded = 0;

    streamProgress(20, "Scanning {$totalAccounts} accounts...");

    // Compare each account with others using weighted scoring
    for ($i = 0; $i < $totalAccounts; $i++) {
        $account1 = $accounts[$i];
        
        for ($j = $i + 1; $j < $totalAccounts; $j++) {
            $account2 = $accounts[$j];

            $reasons = [];
            $score = 0.0;

            // Normalise values
            $email1 = strtolower(trim($account1['email'] ?? ''));
            $email2 = strtolower(trim($account2['email'] ?? ''));
            $phone1 = preg_replace('/[^0-9]/', '', $account1['phone'] ?? '');
            $phone2 = preg_replace('/[^0-9]/', '', $account2['phone'] ?? '');
            $vat1   = preg_replace('/[^0-9]/', '', $account1['vat_no'] ?? '');
            $vat2   = preg_replace('/[^0-9]/', '', $account2['vat_no'] ?? '');
            $reg1   = strtolower(trim($account1['reg_no'] ?? ''));
            $reg2   = strtolower(trim($account2['reg_no'] ?? ''));

            // 1. Exact email match (0.7)
            if ($email1 !== '' && $email1 === $email2) {
                $score += 0.70;
                $reasons[] = 'Same email';
            }

            // 2. Exact phone match (0.7) if at least 9 digits
            if ($phone1 !== '' && $phone1 === $phone2 && strlen($phone1) >= 9) {
                $score += 0.70;
                $reasons[] = 'Same phone';
            }

            // 3. Exact VAT match (0.8) if at least 8 digits
            if ($vat1 !== '' && $vat1 === $vat2 && strlen($vat1) >= 8) {
                $score += 0.80;
                $reasons[] = 'Same VAT number';
            }

            // 4. Exact registration number match (0.8)
            if ($reg1 !== '' && $reg1 === $reg2) {
                $score += 0.80;
                $reasons[] = 'Same registration number';
            }

            // 5. Name similarity – up to 0.5 weighted by similarity ratio
            $similarity = calculateSimilarity($account1['name'], $account2['name']);
            if ($similarity > 0) {
                $nameScore = $similarity * 0.50;
                $score += $nameScore;
                $reasons[] = 'Similar names (' . round($similarity * 100) . '%)';
            }

            // Cap score at 1.0 (100%)
            if ($score > 1.0) {
                $score = 1.0;
            }

            // Determine candidate based on threshold
            if ($score >= $threshold && count($reasons) > 0) {
                $candidatesFound++;

                // Check if this pair already exists (either direction)
                $checkStmt = $DB->prepare("
                    SELECT id FROM crm_merge_candidates 
                    WHERE company_id = ? 
                      AND ((left_id = ? AND right_id = ?) OR (left_id = ? AND right_id = ?))
                ");
                $checkStmt->execute([
                    $companyId,
                    $account1['id'],
                    $account2['id'],
                    $account2['id'],
                    $account1['id']
                ]);

                if (!$checkStmt->fetch()) {
                    // Insert new candidate
                    $insertStmt = $DB->prepare("
                        INSERT INTO crm_merge_candidates 
                            (company_id, left_id, right_id, match_score, reason, created_at)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $insertStmt->execute([
                        $companyId,
                        $account1['id'],
                        $account2['id'],
                        round($score, 2),
                        json_encode($reasons)
                    ]);
                    $candidatesAdded++;
                }
            }
        }

        $accountsScanned++;

        // Update progress (20% to 95%)
        if ($accountsScanned % 10 === 0 || $accountsScanned === $totalAccounts) {
            $progressPercent = 20 + (($accountsScanned / $totalAccounts) * 75);
            streamProgress($progressPercent, "Scanned {$accountsScanned}/{$totalAccounts} accounts...");
        }
    }

    streamProgress(100, 'Scan complete!');
    streamComplete($accountsScanned, $candidatesFound, $candidatesAdded);

} catch (Exception $e) {
    error_log("Dedupe scan error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]) . "\n";
}

// ========== HELPER FUNCTION ==========

function calculateSimilarity($str1, $str2) {
    // Normalize strings
    $str1 = strtolower(trim($str1));
    $str2 = strtolower(trim($str2));

    if ($str1 === $str2) {
        return 1.0;
    }

    if (empty($str1) || empty($str2)) {
        return 0.0;
    }

    // Use Levenshtein distance (simple but effective)
    $maxLen = max(strlen($str1), strlen($str2));
    $distance = levenshtein($str1, $str2);
    
    if ($maxLen === 0) {
        return 0.0;
    }

    $similarity = 1 - ($distance / $maxLen);

    return max(0, min(1, $similarity));
}