<?php
// utils.php
function e($s) {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function redirect($url) {
  header("Location: $url");
  exit;
}