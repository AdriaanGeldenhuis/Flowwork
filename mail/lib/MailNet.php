<?php
// mail/lib/MailNet.php
//
// Host policy for user-configured IMAP/SMTP servers. A mailbox server must
// resolve to public addresses only (A and AAAA records), and the socket then
// connects to the address that was checked, so a DNS answer that changes
// between the check and the connect cannot reach the private network.
//
// Define MAIL_ALLOW_PRIVATE_HOSTS (constant in config.php, or environment
// variable) to allow private hosts, e.g. a mail server on the same LAN or a
// local test server.

final class MailNetException extends RuntimeException {}

final class MailNet
{
    public static function privateAllowed(): bool
    {
        if (defined('MAIL_ALLOW_PRIVATE_HOSTS') && constant('MAIL_ALLOW_PRIVATE_HOSTS')) return true;
        return (bool)getenv('MAIL_ALLOW_PRIVATE_HOSTS');
    }

    /** Null when the host is acceptable, else a message for the user. */
    public static function hostProblem(string $host): ?string
    {
        try {
            self::target($host);
            return null;
        } catch (MailNetException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Address to open the socket to: a checked public IP ("203.0.113.5" or
     * "[2001:db8::5]"), or the host name itself when private hosts are allowed.
     * TLS still verifies the certificate against the host name (peer_name).
     */
    public static function target(string $host): string
    {
        $host = trim($host);
        if ($host === '') throw new MailNetException('No server name given');
        $bare = trim($host, '[]');
        $isIp = (bool)filter_var($bare, FILTER_VALIDATE_IP);
        if (!$isIp && !preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/i', $host)) {
            throw new MailNetException('Server names may only contain letters, digits, dots and hyphens');
        }
        if (self::privateAllowed()) return $isIp && str_contains($bare, ':') ? '[' . $bare . ']' : $bare;

        $ips = $isIp ? [$bare] : self::lookup($host);
        if (!$ips) throw new MailNetException('Could not find server "' . $host . '" — check the spelling');
        foreach ($ips as $ip) {
            if (!self::isPublic($ip)) {
                throw new MailNetException('Server "' . $host . '" points to a private network address');
            }
        }
        // Prefer IPv4: many shared hosts have no IPv6 route.
        $pick = $ips[0];
        foreach ($ips as $ip) {
            if (!str_contains($ip, ':')) { $pick = $ip; break; }
        }
        return str_contains($pick, ':') ? '[' . $pick . ']' : $pick;
    }

    public static function isPublic(string $ip): bool
    {
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m)) $ip = $m[1];
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        $bin = @inet_pton($ip);
        if ($bin === false) return false;
        if (strlen($bin) === 4) {
            // 100.64.0.0/10 (carrier-grade NAT) and 192.0.0.0/24 are not reachable mail hosts.
            if (ord($bin[0]) === 100 && (ord($bin[1]) & 0xC0) === 64) return false;
            if (ord($bin[0]) === 192 && ord($bin[1]) === 0 && ord($bin[2]) === 0) return false;
            return true;
        }
        $b0 = ord($bin[0]);
        $b1 = ord($bin[1]);
        if ($b0 === 0xFE && ($b1 & 0xC0) === 0x80) return false;            // fe80::/10 link-local
        if (($b0 & 0xFE) === 0xFC) return false;                             // fc00::/7 unique local
        if (substr($bin, 0, 12) === str_repeat("\0", 12)) return false;      // ::, ::1, IPv4-compatible
        if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xFF\xFF") return false; // IPv4-mapped
        if (substr($bin, 0, 12) === "\x00\x64\xFF\x9B" . str_repeat("\0", 8)) return false; // NAT64
        return true;
    }

    /** @return string[] every A and AAAA address of the host */
    private static function lookup(string $host): array
    {
        $ips = [];
        if (function_exists('dns_get_record')) {
            $recs = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($recs)) {
                foreach ($recs as $r) {
                    if (!empty($r['ip'])) $ips[] = $r['ip'];
                    if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
                }
            }
        }
        // Names only the local resolver knows (hosts file) — still checked above.
        if (!$ips) $ips = @gethostbynamel($host) ?: [];
        return array_values(array_unique($ips));
    }
}
