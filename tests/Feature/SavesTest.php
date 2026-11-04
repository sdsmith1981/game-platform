<?php

use GamePlatform\Http\Middleware\DecompressGzipRequest;
use GamePlatform\Saves\SaveFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Actions\SaveGame;
use Workbench\App\Models\Game;

beforeEach(function () {
    Config::set('filesystems.games', 'games');
    Config::set('filesystems.disks.games', ['driver' => 'local', 'root' => storage_path('games')]);
    Storage::fake('games');
});

test('each save is a new numbered file, and only the last few are kept', function () {
    $game = Game::create();
    $save = new SaveGame;

    foreach ([1, 2, 3, 4, 5] as $revision) {
        $state = $save->handle($game, ['day' => 100 + $revision], $revision - 1);
        expect($state->revision)->toBe($revision);
    }

    $state = $game->state()->sole();
    expect($state->revision)->toBe(5)
        ->and($state->state)->toBeNull()
        ->and($state->path)->toBe("games/{$game->id}/saves/5.json.gz")
        ->and($state->data())->toBe(['day' => 105])
        ->and($game->refresh()->day)->toBe(105)
        ->and(SaveFiles::disk()->files("games/{$game->id}/saves"))
        ->toEqualCanonicalizing(["games/{$game->id}/saves/3.json.gz", "games/{$game->id}/saves/4.json.gz", "games/{$game->id}/saves/5.json.gz"]);
});

test('a save made from an older copy of the game is refused', function () {
    $game = Game::create();
    $save = new SaveGame;
    $save->handle($game, ['day' => 1]);

    // Two tabs open revision 1; the first saves.
    expect($save->handle($game, ['day' => 200], 1))->not->toBeNull()
        ->and($save->handle($game, ['day' => 150], 1))->toBeNull()
        ->and($game->state()->sole()->data()['day'])->toBe(200)
        ->and($game->refresh()->day)->toBe(200);
});

test('a save without a base revision replaces whatever is there', function () {
    $game = Game::create();
    $save = new SaveGame;
    $save->handle($game, ['day' => 1]);
    $save->handle($game, ['day' => 2]);

    expect($save->handle($game, ['day' => 3])->revision)->toBe(3);
});

test('saves stay in the database in production until a bucket is attached', function () {
    $this->app['env'] = 'production';
    $game = Game::create();

    $state = (new SaveGame)->handle($game, ['day' => 9]);

    expect(SaveFiles::enabled())->toBeFalse()
        ->and($state->path)->toBeNull()
        ->and($state->state)->not->toBeNull()
        ->and($state->refresh()->data())->toBe(['day' => 9])
        ->and($state->link())->toBeNull();
});

test("a deleted game's files can be removed", function () {
    $game = Game::create();
    (new SaveGame)->handle($game, ['day' => 1]);

    SaveFiles::deleteGame($game->id);

    expect(SaveFiles::disk()->allFiles())->toBe([]);
});

test('gzipped JSON bodies are unzipped', function () {
    Route::put('save', fn (Request $request) => ['day' => $request->input('state.day')])
        ->middleware(DecompressGzipRequest::class);

    $this->call('PUT', '/save', [], [], [], ['HTTP_CONTENT_ENCODING' => 'gzip', 'CONTENT_TYPE' => 'application/json'], gzencode('{"state":{"day":42}}'))
        ->assertOk()
        ->assertJson(['day' => 42]);

    $this->call('PUT', '/save', [], [], [], ['HTTP_CONTENT_ENCODING' => 'gzip', 'CONTENT_TYPE' => 'application/json'], 'not gzip')
        ->assertStatus(400);

    $this->call('PUT', '/save', [], [], [], ['HTTP_CONTENT_ENCODING' => 'gzip', 'CONTENT_TYPE' => 'application/json'], gzencode('not json'))
        ->assertStatus(400);

    // Plain JSON passes through untouched.
    $this->putJson('/save', ['state' => ['day' => 7]])->assertJson(['day' => 7]);
});

test('a gzipped body that is cut short is refused', function () {
    Route::put('save', fn () => 'ok')->middleware(DecompressGzipRequest::class);
    $cut = substr(gzencode('{"state":{"day":42,"pad":"'.bin2hex(random_bytes(5000)).'"}}'), 0, 200);

    $this->call('PUT', '/save', [], [], [], ['HTTP_CONTENT_ENCODING' => 'gzip', 'CONTENT_TYPE' => 'application/json'], $cut)
        ->assertStatus(400);
});

test('unzipped bodies are capped', function () {
    Route::put('save', fn () => 'ok')->middleware(DecompressGzipRequest::class);
    $huge = gzencode('{"pad":"'.str_repeat('a', DecompressGzipRequest::MAX_BYTES).'"}');

    expect(strlen($huge))->toBeLessThan(100_000);

    $this->call('PUT', '/save', [], [], [], ['HTTP_CONTENT_ENCODING' => 'gzip', 'CONTENT_TYPE' => 'application/json'], $huge)
        ->assertStatus(400);

    // Just under the cap is fine.
    $big = gzencode('{"pad":"'.str_repeat('a', DecompressGzipRequest::MAX_BYTES - 20).'"}');
    $this->call('PUT', '/save', [], [], [], ['HTTP_CONTENT_ENCODING' => 'gzip', 'CONTENT_TYPE' => 'application/json'], $big)
        ->assertOk();
});
