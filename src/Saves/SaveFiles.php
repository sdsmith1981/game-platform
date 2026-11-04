<?php

namespace GamePlatform\Saves;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

/**
 * Where a game's large files live: its saves, and anything else a game keeps per save
 * (a map, a picture). They're kept in object storage (Laravel Cloud's, run on Cloudflare
 * R2) rather than the database, and browsers fetch them straight from there through
 * short-lived links, so they don't pass through the app or weigh on the database.
 *
 * The disk is named by `filesystems.games`. Each game's files sit under games/{id}/.
 * Saves are numbered: each one is a new file, and the last few are kept. Games extend
 * this class to name their own files.
 */
class SaveFiles
{
    /** Saves kept per game, newest first; older ones are deleted. */
    public const int KEEP_SAVES = 3;

    /** How long a link to a file works for, in minutes. */
    public const int LINK_MINUTES = 60;

    /**
     * Whether files go to storage at all. Not in production on a local disk: Laravel
     * Cloud's is wiped at every deploy, so until a bucket is attached they stay in the
     * database.
     */
    public static function enabled(): bool
    {
        $disk = Config::get('filesystems.games');
        if (! is_string($disk) || $disk === '') {
            return false;
        }

        return ! (app()->isProduction() && Config::get("filesystems.disks.{$disk}.driver") === 'local');
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(Config::string('filesystems.games'));
    }

    /** The folder holding one game's files. */
    public static function folder(int $gameId): string
    {
        return "games/{$gameId}";
    }

    public static function savePath(int $gameId, int $revision): string
    {
        return self::folder($gameId)."/saves/{$revision}.json.gz";
    }

    public static function put(string $path, string $contents, string $type): void
    {
        self::disk()->put($path, $contents, ['ContentType' => $type]);
    }

    /**
     * A short-lived link the browser can fetch the file from directly, or null where the
     * disk can't make one: the app then sends the file itself.
     */
    public static function link(string $path): ?string
    {
        $disk = self::disk();
        if (! method_exists($disk, 'providesTemporaryUrls') || ! $disk->providesTemporaryUrls()) {
            return null;
        }

        return $disk->temporaryUrl($path, now()->addMinutes(self::LINK_MINUTES));
    }

    /** Remove a game's files, once the game itself has gone. */
    public static function deleteGame(int $gameId): void
    {
        self::disk()->deleteDirectory(self::folder($gameId));
    }
}
