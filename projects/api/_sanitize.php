<?php
// Input sanitization helpers

function sanitizeHTML($input) {
    // Allow basic formatting but strip dangerous tags
    $allowed = '<p><br><strong><em><u><ol><ul><li><a><span>';
    return strip_tags($input, $allowed);
}

function sanitizeFilename($filename) {
    // Remove directory traversal attempts
    $filename = basename($filename);
    // Remove special chars except dots, dashes, underscores
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
    // Limit length
    return substr($filename, 0, 255);
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

function validateURL($url) {
    return filter_var($url, FILTER_VALIDATE_URL);
}

function validateJSON($json) {
    json_decode($json);
    return json_last_error() === JSON_ERROR_NONE;
}

function escapeOutput($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}