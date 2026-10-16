<?php
// mail/lib/autoload.php — single include for the Mail library.

class MailPaths
{
    /** Absolute path of the Flowwork web root. */
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * storage/mail — attachment store, outgoing .eml spool, rate-limit buckets
     * and the schema marker. storage/ is denied over HTTP by storage/.htaccess;
     * files are only ever served through mail/api/attachment.php.
     */
    public static function storageDir(): ?string
    {
        static $dir = false;
        if ($dir !== false) return $dir;
        $path = self::root() . '/storage/mail';
        if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) {
            error_log('[mail] cannot create ' . $path);
            return $dir = null;
        }
        return $dir = $path;
    }

    /** Resolve a stored relative path (e.g. "storage/mail/1/2/x.bin") safely. */
    public static function resolveStored(string $relative): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains($relative, "\0")) return null;
        $full = realpath(self::root() . '/' . $relative);
        if ($full === false || !is_file($full)) return null;
        $allowed = [realpath(self::root() . '/storage'), realpath(self::root() . '/uploads')];
        foreach ($allowed as $base) {
            if ($base && str_starts_with($full, $base . DIRECTORY_SEPARATOR)) return $full;
        }
        return null;
    }
}

require_once __DIR__ . '/SecureVault.php';
require_once __DIR__ . '/MailNet.php';
require_once __DIR__ . '/MailSchema.php';
require_once __DIR__ . '/HtmlSanitizer.php';
require_once __DIR__ . '/MimeParser.php';
require_once __DIR__ . '/MimeBuilder.php';
require_once __DIR__ . '/SmtpSender.php';
require_once __DIR__ . '/ImapClient.php';
require_once __DIR__ . '/TemplateEngine.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/MailRepo.php';
require_once __DIR__ . '/MailActions.php';
require_once __DIR__ . '/RulesEngine.php';
require_once __DIR__ . '/MailStore.php';
require_once __DIR__ . '/MailSyncService.php';
require_once __DIR__ . '/MailOutbox.php';
require_once __DIR__ . '/MailWorker.php';
