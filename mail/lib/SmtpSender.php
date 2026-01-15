<?php
// mail/lib/SmtpSender.php
class SmtpSender {
  public static function send(array $opts): array {
    $host = $opts['host'] ?? '';
    $port = (int)($opts['port'] ?? 587);
    $enc  = strtolower($opts['encryption'] ?? 'tls'); // 'ssl' | 'tls' | 'none'
    $user = $opts['username'] ?? '';
    $pass = $opts['password'] ?? '';
    $from = $opts['from'] ?? '';
    $to   = (array)($opts['to'] ?? []);
    $cc   = (array)($opts['cc'] ?? []);
    $bcc  = (array)($opts['bcc'] ?? []);
    $subj = $opts['subject'] ?? '';
    $html = $opts['html'] ?? '';
    $text = $opts['text'] ?? strip_tags($html);

    if (!$host || !$from || !$to) {
      return ['ok'=>false,'error'=>'Missing SMTP parameters'];
    }

    // Use PHP's mail() as a fallback if sockets are disabled
    if (!function_exists('fsockopen')) {
      $headers = "From: $from\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
      @mail(implode(',', $to), $subj, $html, $headers);
      return ['ok'=>true,'transport'=>'mail() fallback'];
    }

    $remote = ($enc === 'ssl') ? "ssl://$host" : $host;
    $fp = @fsockopen($remote, $port, $errno, $errstr, 15);
    if (!$fp) return ['ok'=>false,'error'=>"SMTP connect: $errstr ($errno)"];

    $read = function() use ($fp) { $out=''; while ($line=fgets($fp, 515)) { $out.=$line; if (preg_match('/^\d{3} /', $line)) break; } return $out; };
    $cmd  = function($c) use ($fp,$read){ fputs($fp, $c."\r\n"); return $read(); };

    $banner = $read();
    $ehloHost = 'localhost';
    $resp = $cmd("EHLO $ehloHost");
    if (stripos($resp,'STARTTLS') !== false && $enc === 'tls') {
      $cmd("STARTTLS");
      if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        return ['ok'=>false,'error'=>'STARTTLS failed'];
      }
      $resp = $cmd("EHLO $ehloHost");
    }
    if ($user && $pass) {
      $cmd("AUTH LOGIN");
      $cmd(base64_encode($user));
      $resp = $cmd(base64_encode($pass));
      if (strpos($resp,'235') !== 0) return ['ok'=>false,'error'=>'SMTP auth failed'];
    }
    $cmd("MAIL FROM:<$from>");
    foreach ($to as $r) $cmd("RCPT TO:<$r>");
    foreach ($cc as $r) $cmd("RCPT TO:<$r>");
    foreach ($bcc as $r) $cmd("RCPT TO:<$r>");
    $cmd("DATA");
    $boundary = 'b'.bin2hex(random_bytes(6));
    $headers = [];
    $headers[] = "From: <$from>";
    if ($cc)  $headers[] = "Cc: ".implode(',', $cc);
    if ($bcc) $headers[] = "Bcc: ".implode(',', $bcc);
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: multipart/alternative; boundary=\"$boundary\"";
    $body = [];
    $body[] = "--$boundary";
    $body[] = "Content-Type: text/plain; charset=UTF-8";
    $body[] = "Content-Transfer-Encoding: 8bit\r\n";
    $body[] = $text;
    $body[] = "--$boundary";
    $body[] = "Content-Type: text/html; charset=UTF-8";
    $body[] = "Content-Transfer-Encoding: 8bit\r\n";
    $body[] = $html;
    $body[] = "--$boundary--";

    $data  = "Subject: $subj\r\n".implode("\r\n",$headers)."\r\n\r\n".implode("\r\n",$body)."\r\n.";
    $resp  = $cmd($data);
    $cmd("QUIT");
    fclose($fp);
    if (strpos($resp,'250') !== 0) return ['ok'=>false,'error'=>'SMTP send failed'];
    return ['ok'=>true];
  }
}