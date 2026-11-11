<?php

namespace App\Support;

/**
 * Resolves the per-OS user-data root and its subdirectories.
 *
 * User data (database, media, keys, backups, settings, logs) lives outside the
 * application bundle so app updates never destroy user files. The
 * AIVIDEO_DATA_DIR environment value always wins over the OS default.
 */
class StudioPaths
{
    public static function root(): string
    {
        $override = trim((string) config('studio.data_dir', ''));

        if ($override !== '') {
            return rtrim($override, '/');
        }

        return match (PHP_OS_FAMILY) {
            'Darwin' => self::home().'/Library/Application Support/AI Reels Studio',
            'Windows' => self::windowsAppData().'/AI Reels Studio',
            default => self::home().'/.local/share/AI Reels Studio',
        };
    }

    public static function subDirectory(string $name): string
    {
        return self::root().'/'.$name;
    }

    public static function database(): string
    {
        return self::subDirectory('database').'/database.sqlite';
    }

    public static function videos(): string
    {
        return self::subDirectory('videos');
    }

    public static function uploads(): string
    {
        return self::subDirectory('uploads');
    }

    public static function backups(): string
    {
        return self::subDirectory('backups');
    }

    public static function settings(): string
    {
        return self::subDirectory('settings');
    }

    public static function keys(): string
    {
        return self::subDirectory('keys');
    }

    public static function logs(): string
    {
        return self::subDirectory('logs');
    }

    public static function tmp(): string
    {
        return self::subDirectory('tmp');
    }

    public static function progress(): string
    {
        return self::tmp().'/progress';
    }

    public static function backgrounds(): string
    {
        return self::videos().'/backgrounds';
    }

    public static function updates(): string
    {
        return self::tmp().'/updates';
    }

    /** Create every data subdirectory when missing. */
    public static function ensure(): void
    {
        foreach ([self::database(), self::videos(), self::uploads(), self::backups(), self::settings(), self::keys(), self::logs(), self::progress(), self::backgrounds()] as $path) {
            $directory = str_ends_with($path, '.sqlite') ? dirname($path) : $path;

            if (! is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }
        }
    }

    private static function home(): string
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? null);

        return $home === false || $home === null || $home === '' ? '.' : rtrim($home, '/');
    }

    private static function windowsAppData(): string
    {
        $appData = getenv('APPDATA');

        return $appData === false || $appData === '' ? self::home().'/AppData/Roaming' : rtrim($appData, '/');
    }
}
