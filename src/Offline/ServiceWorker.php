<?php

namespace GamePlatform\Offline;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;

/**
 * The service worker for offline play (resources/sw/sw.js), with what it keeps: the
 * built code and styles from Vite's manifest, the offline page and whatever else the
 * game names. Its version changes with any of them, so browsers pick up a new one
 * after each deploy.
 *
 * Serve it from the site root, as /sw.js, so it can look after every page.
 */
class ServiceWorker
{
    /**
     * @param  string  $cachePrefix  The game's caches are named with this, e.g. "my-game-".
     * @param  list<string>  $keep  Site paths to keep besides the built files, e.g. "/offline".
     * @param  string  $versionSalt  Anything else that should make a new version when it changes.
     * @param  list<string>  $oldPrefixes  Cache prefixes the game used before, to clear out.
     */
    public static function response(string $cachePrefix, array $keep, string $versionSalt = '', array $oldPrefixes = []): Response
    {
        $files = Vite::isRunningHot() ? [] : self::builtFiles(public_path('build/manifest.json'));
        $version = substr(hash('sha256', implode("\n", $files).$versionSalt), 0, 16);

        $script = 'const PREFIX = '.json_encode($cachePrefix).";\n"
            .'const OLD_PREFIXES = '.json_encode($oldPrefixes).";\n"
            .'const VERSION = '.json_encode($version).";\n"
            .'const PRECACHE = '.json_encode([...$files, ...$keep], JSON_UNESCAPED_SLASHES).";\n"
            .File::get(__DIR__.'/../../resources/sw/sw.js');

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/',
        ]);
    }

    /**
     * Every file in Vite's build manifest, as site paths. None while the dev server is
     * running: its files change as they're edited.
     *
     * @return list<string>
     */
    public static function builtFiles(string $manifest): array
    {
        if (! File::exists($manifest)) {
            return [];
        }

        $files = [];
        foreach ((array) json_decode(File::get($manifest), true) as $chunk) {
            if (! is_array($chunk)) {
                continue;
            }
            foreach ([[$chunk['file'] ?? null], $chunk['css'] ?? [], $chunk['assets'] ?? []] as $paths) {
                foreach ((array) $paths as $path) {
                    if (is_string($path)) {
                        $files['/build/'.$path] = true;
                    }
                }
            }
        }

        return array_keys($files);
    }
}
