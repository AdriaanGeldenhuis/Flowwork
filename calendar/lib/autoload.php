<?php
// calendar/lib/autoload.php — single include for the Calendar library.
// Used by the calendar pages and API, the reminder job, and the modules that
// put things on the calendar (CRM follow-ups, Projects, Quotes & Invoices).

class CalendarPaths
{
    /** Absolute path of the Flowwork web root. */
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * storage/calendar — event attachments, rate-limit buckets and the schema
     * marker. storage/ is denied over HTTP by storage/.htaccess; attachments are
     * only ever served through calendar/api/attachment.php.
     */
    public static function storageDir(): ?string
    {
        static $dir = false;
        if ($dir !== false) return $dir;
        $path = self::root() . '/storage/calendar';
        if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) {
            error_log('[calendar] cannot create ' . $path);
            return $dir = null;
        }
        return $dir = $path;
    }

    /** Resolve a stored relative path (e.g. "storage/calendar/1/9/x.bin") safely. */
    public static function resolveStored(string $relative): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains($relative, "\0")) return null;
        $full = realpath(self::root() . '/' . $relative);
        $base = realpath(self::root() . '/storage/calendar');
        if ($full === false || $base === false || !is_file($full)) return null;
        return str_starts_with($full, $base . DIRECTORY_SEPARATOR) ? $full : null;
    }
}

/** A message that is safe to show the user as-is (validation, permissions). */
class CalendarUserError extends RuntimeException {}

require_once __DIR__ . '/CalendarSchema.php';
require_once __DIR__ . '/Recurrence.php';
require_once __DIR__ . '/CalendarRepo.php';
require_once __DIR__ . '/CalendarSettings.php';
require_once __DIR__ . '/CalendarSources.php';
require_once __DIR__ . '/CalendarLinks.php';
require_once __DIR__ . '/Ics.php';
require_once __DIR__ . '/CalendarLink.php';
