<?php
// /projects/api/_respond.php

function respond_ok($data = null) {
    while (ob_get_level()) ob_end_clean();
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

function respond_error($message, $code = 400) {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}