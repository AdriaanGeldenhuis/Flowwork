<?php
// Rate limiting helper
function checkRateLimit($action, $maxAttempts = 10, $timeWindow = 60) {
    global $DB, $USER_ID, $COMPANY_ID;
    
    $key = $action . '_' . $USER_ID . '_' . $COMPANY_ID;
    $cacheFile = sys_get_temp_dir() . '/flowwork_ratelimit_' . md5($key) . '.json';
    
    $now = time();
    $attempts = [];
    
    if (file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        $attempts = array_filter($data['attempts'] ?? [], function($timestamp) use ($now, $timeWindow) {
            return ($now - $timestamp) < $timeWindow;
        });
    }
    
    if (count($attempts) >= $maxAttempts) {
        http_response_code(429);
        echo json_encode([
            'ok' => false,
            'error' => 'Rate limit exceeded. Please wait before trying again.',
            'retry_after' => $timeWindow - ($now - min($attempts))
        ]);
        exit;
    }
    
    $attempts[] = $now;
    file_put_contents($cacheFile, json_encode(['attempts' => $attempts]));
}