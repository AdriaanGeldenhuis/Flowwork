<?php
// /projects/api/_respond.php

function respond_ok($data = null) {
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

function respond_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}