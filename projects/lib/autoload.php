<?php
// projects/lib/autoload.php — single include for the Projects library.
// Used by the project pages and API, the reminder job, and the modules that
// show or link project work (Calendar, Home, Mail, CRM, Quotes & Invoices …).

class ProjectPaths
{
    /** Absolute path of the Flowwork web root. */
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * storage/projects — item files, the schema marker and rate-limit buckets.
     * storage/ is denied over HTTP by storage/.htaccess; files are only served
     * through projects/api/file.php.
     */
    public static function storageDir(): ?string
    {
        static $dir = false;
        if ($dir !== false) return $dir;
        $path = self::root() . '/storage/projects';
        if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) {
            error_log('[projects] cannot create ' . $path);
            return $dir = null;
        }
        return $dir = $path;
    }

    /**
     * Resolve a stored attachment path safely. New files live under
     * storage/projects/; files uploaded by the old boards live under
     * uploads/projects/ (or uploads/boards/). Anything else is refused.
     */
    public static function resolveStored(string $stored): ?string
    {
        $stored = str_replace('\\', '/', $stored);
        if ($stored === '' || str_contains($stored, "\0") || str_contains($stored, '..')) return null;
        // The old boards saved absolute paths under /workdrive.co.za/flowwork/.
        $legacy = '/workdrive.co.za/flowwork';
        if (str_starts_with($stored, $legacy . '/')) {
            $full = realpath($stored);
            $b = realpath($legacy);
            return ($full !== false && $b !== false && is_file($full) && str_starts_with($full, $b . DIRECTORY_SEPARATOR)) ? $full : null;
        }
        $full = realpath(self::root() . '/' . ltrim($stored, '/'));
        if ($full === false || !is_file($full)) return null;
        foreach (['storage/projects', 'uploads/projects', 'uploads/boards', 'uploads/board_files'] as $base) {
            $b = realpath(self::root() . '/' . $base);
            if ($b !== false && str_starts_with($full, $b . DIRECTORY_SEPARATOR)) return $full;
        }
        return null;
    }
}

/** A message that is safe to show the user as-is (validation, permissions). */
class ProjectUserError extends RuntimeException {}

/** The user may not do this (shown as-is, HTTP 403). */
class ProjectForbidden extends ProjectUserError {}

require_once __DIR__ . '/Formula.php';
require_once __DIR__ . '/Columns.php';
require_once __DIR__ . '/ProjectSchema.php';
require_once __DIR__ . '/ProjectRepo.php';
require_once __DIR__ . '/BoardRepo.php';
require_once __DIR__ . '/ItemRepo.php';
require_once __DIR__ . '/Activity.php';
require_once __DIR__ . '/Automations.php';
require_once __DIR__ . '/ProjectNotify.php';
require_once __DIR__ . '/Templates.php';
require_once __DIR__ . '/Guests.php';
require_once __DIR__ . '/ProjectLink.php';
