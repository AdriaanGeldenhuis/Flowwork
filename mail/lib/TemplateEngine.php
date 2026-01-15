<?php
// mail/lib/TemplateEngine.php
class TemplateEngine {
  public static function render(string $tpl, array $ctx): string {
    if ($tpl === '') return '';
    // case-insensitive map
    $map = [];
    foreach ($ctx as $k=>$v) $map[strtoupper($k)] = $v;
    return preg_replace_callback('/\{([A-Z0-9_]+)\}/i', function($m) use ($map){
      $key = strtoupper($m[1]);
      return array_key_exists($key, $map) ? (string)$map[$key] : $m[0];
    }, $tpl);
  }
}