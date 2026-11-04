<?php

use GamePlatform\Offline\ServiceWorker;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('sw.js', fn () => ServiceWorker::response('my-game-', ['/offline', '/icon.png'], 'config-1', ['old-name-']));
});

test('the service worker keeps the offline page, and covers the whole site', function () {
    $response = $this->get('/sw.js')->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
        ->assertHeader('Service-Worker-Allowed', '/')
        ->assertHeader('Cache-Control', 'no-cache, private');

    $script = (string) $response->getContent();
    expect($script)->toStartWith("const PREFIX = \"my-game-\";\nconst OLD_PREFIXES = [\"old-name-\"];\nconst VERSION = \"")
        ->and($script)->toContain('"/offline"')
        ->and($script)->toContain('"/icon.png"')
        ->and($script)->toContain("addEventListener('fetch'");
});

test('the version changes with what the game says should change it', function () {
    $version = fn () => substr((string) $this->get('/sw.js')->getContent(), 0, 200);
    $first = $version();

    Route::get('sw.js', fn () => ServiceWorker::response('my-game-', ['/offline'], 'config-2'));

    expect($version())->not->toBe($first);
});

test("the worker keeps every built file: each chunk's code, styles and assets", function () {
    $manifest = storage_path('framework/testing/manifest.json');
    File::ensureDirectoryExists(dirname($manifest));
    File::put($manifest, (string) json_encode([
        'resources/js/app.ts' => ['file' => 'assets/app-abc.js', 'css' => ['assets/app-def.css']],
        'resources/js/pages/games/Play.vue' => ['file' => 'assets/Play-123.js', 'assets' => ['assets/font-1.woff2'], 'css' => ['assets/app-def.css']],
    ]));

    expect(ServiceWorker::builtFiles($manifest))->toBe([
        '/build/assets/app-abc.js',
        '/build/assets/app-def.css',
        '/build/assets/Play-123.js',
        '/build/assets/font-1.woff2',
    ])->and(ServiceWorker::builtFiles(storage_path('framework/testing/missing.json')))->toBe([]);

    File::delete($manifest);
});
