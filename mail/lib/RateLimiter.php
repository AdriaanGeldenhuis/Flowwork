<?php
// mail/lib/RateLimiter.php — sliding-window limiter backed by small JSON files
// under storage/mail/ratelimit (flock-protected; shared-hosting friendly).

class RateLimiter
{
    /** Records a hit; returns seconds to wait (0 = allowed). */
    public static function hit(string $key, int $max, int $windowSeconds): int
    {
        $dir = MailPaths::storageDir();
        if ($dir === null) return 0;
        $dir .= '/ratelimit';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $file = $dir . '/' . sha1($key) . '.json';

        $fh = @fopen($file, 'c+');
        if (!$fh) return 0;
        try {
            flock($fh, LOCK_EX);
            $now = time();
            $raw = stream_get_contents($fh);
            $hits = json_decode($raw ?: '[]', true);
            if (!is_array($hits)) $hits = [];
            $hits = array_values(array_filter($hits, fn($t) => is_int($t) && $t > $now - $windowSeconds));
            if (count($hits) >= $max) {
                return max(1, $hits[0] + $windowSeconds - $now);
            }
            $hits[] = $now;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($hits));
            return 0;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
