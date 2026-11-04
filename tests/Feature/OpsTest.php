<?php

use GamePlatform\Billing\Events\CheckoutStarted;
use GamePlatform\Billing\Events\PlayerActive;
use GamePlatform\Billing\PlayTime;
use GamePlatform\Billing\Purchase;
use GamePlatform\Mail\QueuedMail;
use GamePlatform\Ops\BrowserError;
use GamePlatform\Ops\Feedback;
use GamePlatform\Ops\FeedbackContext;
use GamePlatform\Ops\Stats;
use GamePlatform\Ops\WhatsNew;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Workbench\App\Models\Game;
use Workbench\App\Models\User;
use Workbench\App\Notifications\Welcome;

function member(array $attributes = []): User
{
    $user = new User;
    $user->forceFill([
        'name' => 'Pat Player',
        'email' => fake()->unique()->safeEmail(),
        'password' => 'password',
        'email_verified_at' => now(),
        ...$attributes,
    ])->save();

    return $user->refresh();
}

function feedbackReport(array $overrides = []): array
{
    return [
        'type' => 'bug',
        'title' => 'Buses get stuck at the depot',
        'body' => 'They never leave after I press Start.',
        'meta' => ['url' => '/games/x', 'viewport' => '1280×800'],
        'errors' => ['TypeError: x is undefined'],
        ...$overrides,
    ];
}

function tally(string $metric, string $dimension = ''): int
{
    return (int) DB::table('daily_stats')->where('metric', $metric)->where('dimension', $dimension)->sum('tally');
}

beforeEach(function () {
    Config::set('services.github_issues.token', 'test-token');
    Config::set('services.github_issues.repo', 'owner/repo');
});

test("a browser error is reported like the server's, from the script it happened in", function () {
    Exceptions::fake();

    $this->postJson(route('client-errors.store'), [
        'message' => "TypeError: can't read 'x' of undefined for player@example.com",
        'source' => 'https://game.example/build/assets/Hud-abc.js',
        'line' => 42,
        'column' => 7,
        'stack' => "at draw (Hud-abc.js:42:7)\nuser=player@example.com",
        'page' => '/games/abc?token=secret',
    ])->assertNoContent();

    Exceptions::assertReported(fn (BrowserError $e) => $e->getMessage() === "TypeError: can't read 'x' of undefined for [email]"
        && $e->getFile() === '/build/assets/Hud-abc.js'
        && $e->getLine() === 42
        && $e->context()['page'] === '/games/abc'
        && ! str_contains($e->context()['stack'], 'player@example.com'));
});

test('browser error reports are checked and rate limited', function () {
    Exceptions::fake();

    $this->postJson(route('client-errors.store'), ['message' => ''])->assertJsonValidationErrors('message');
    for ($i = 0; $i < 19; $i++) {
        $this->postJson(route('client-errors.store'), ['message' => 'Boom'])->assertNoContent();
    }
    $this->postJson(route('client-errors.store'), ['message' => 'Boom'])->assertTooManyRequests();
});

test('a bug report becomes a GitHub issue with the details, never the email', function () {
    Http::fake(['api.github.com/*' => Http::response(['html_url' => 'https://github.com/owner/repo/issues/7'], 201)]);
    $user = member(['email' => 'player@example.com']);

    $this->actingAs($user)->postJson(route('feedback.store'), feedbackReport(['meta' => ['viewport' => '1280×800', 'email' => 'player@example.com']]))
        ->assertOk()
        ->assertJson(['sent' => true]);

    expect(Feedback::sole())
        ->status->toBe(Feedback::SENT)
        ->issue_url->toBe('https://github.com/owner/repo/issues/7')
        ->user_id->toBe($user->id);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/repos/owner/repo/issues'
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && $request['title'] === '[Bug] Buses get stuck at the depot'
        && $request['labels'] === ['bug', 'from-player']
        && str_contains($request['body'], '| viewport | 1280×800 |')
        && str_contains($request['body'], "| user | {$user->id} |")
        && str_contains($request['body'], '| play-time | 120 min left |')
        && str_contains($request['body'], 'TypeError: x is undefined')
        && ! str_contains($request['body'], 'player@example.com'));
});

test("a game adds its own details, and a save snapshot of the player's game when they agree", function () {
    Http::fake(['api.github.com/*' => Http::response(['html_url' => 'u'], 201)]);
    $user = member();
    $mine = Game::create(['day' => 12]);
    $this->app->instance(FeedbackContext::class, new class($mine) implements FeedbackContext
    {
        public function __construct(private Game $mine) {}

        public function game(Model $user, string $game): ?Model
        {
            return $game === 'mine' ? $this->mine : null;
        }

        public function details(?Model $game): array
        {
            return ['game day' => $game?->getAttribute('day')];
        }

        public function snapshot(Model $game): ?string
        {
            return 'gzipped save';
        }
    });

    $this->actingAs($user)->postJson(route('feedback.store'), feedbackReport(['game' => 'mine', 'include_state' => true]));
    $this->actingAs($user)->postJson(route('feedback.store'), feedbackReport(['game' => 'mine', 'include_state' => false]));
    $this->actingAs($user)->postJson(route('feedback.store'), feedbackReport(['game' => 'theirs', 'include_state' => true]));

    [$withState, $without, $notMine] = Feedback::query()->oldest('id')->get()->all();
    expect($withState->state)->toBe('gzipped save')
        ->and($withState->game_id)->toBe($mine->id)
        ->and($withState->meta['game day'])->toBe(12)
        ->and($without->state)->toBeNull()
        ->and($notMine->state)->toBeNull()
        ->and($notMine->game_id)->toBeNull();
    Http::assertSent(fn (Request $r) => str_contains($r['body'], 'save snapshot stored'));
});

test('if GitHub fails the report is kept, and can be sent later', function () {
    Http::fakeSequence('api.github.com/*')
        ->push(['message' => 'Bad credentials'], 401)
        ->push(['html_url' => 'https://github.com/owner/repo/issues/9'], 201);

    $this->actingAs(member())->postJson(route('feedback.store'), feedbackReport())->assertJson(['sent' => false]);
    expect(Feedback::sole()->status)->toBe(Feedback::FAILED);

    $this->artisan('feedback:resend')->assertSuccessful();
    expect(Feedback::sole())
        ->status->toBe(Feedback::SENT)
        ->issue_url->toBe('https://github.com/owner/repo/issues/9');
});

test('feedback is validated, rate limited and for players only', function () {
    Http::fake(['api.github.com/*' => Http::response(['html_url' => 'u'], 201)]);
    $user = member();

    $this->postJson(route('feedback.store'), feedbackReport())->assertUnauthorized();
    $this->actingAs($user)->postJson(route('feedback.store'), ['type' => 'rant', 'title' => '', 'body' => ''])
        ->assertJsonValidationErrors(['type', 'title', 'body']);
    for ($i = 0; $i < 4; $i++) {
        $this->actingAs($user)->postJson(route('feedback.store'), feedbackReport())->assertOk();
    }
    $this->actingAs($user)->postJson(route('feedback.store'), feedbackReport())->assertTooManyRequests();
});

test('players see the releases since the last one they closed, once, and at most a few', function () {
    Config::set('whats-new.releases', [
        ['id' => 'four', 'date' => '2026-10-08', 'title' => 'Four', 'items' => ['d']],
        ['id' => 'three', 'date' => '2026-10-07', 'title' => 'Three', 'items' => ['c']],
        ['id' => 'two', 'date' => '2026-10-06', 'title' => 'Two', 'items' => ['b']],
        ['id' => 'one', 'date' => '2026-10-05', 'title' => 'One', 'items' => ['a']],
    ]);
    $user = member(['whats_new_seen' => 'two']);

    expect(array_column(WhatsNew::unseenBy($user), 'id'))->toBe(['four', 'three'])
        ->and(array_column(WhatsNew::unseenBy(member()), 'id'))->toBe(['four', 'three', 'two']);

    $this->actingAs($user)->post(route('whats-new.seen'))->assertNoContent();
    expect($user->refresh()->whats_new_seen)->toBe('four')
        ->and(WhatsNew::unseenBy($user))->toBe([]);

    auth()->logout();
    $this->post(route('whats-new.seen'))->assertRedirect(route('login'));
});

test('stats count per day, and never fail what they count', function () {
    $this->freezeTime();

    Stats::record(Stats::SIGNUP);
    Stats::record(Stats::SIGNUP);
    Stats::record(Stats::PURCHASE, 'day', 100);
    Stats::record(Stats::PURCHASE, 'day', 100);

    expect(tally(Stats::SIGNUP))->toBe(2)
        ->and((int) DB::table('daily_stats')->where('metric', Stats::PURCHASE)->value('amount'))->toBe(200);

    // A lost count never fails the request it was counting.
    DB::statement('DROP TABLE daily_stats');
    expect(fn () => Stats::record(Stats::SIGNUP))->not->toThrow(Throwable::class);
});

test('checkouts, payments and players coming back are counted', function () {
    $user = member(['created_at' => '2026-10-01 10:00:00']);
    $purchase = $user->purchases()->create([
        'product' => 'day', 'amount' => 100, 'currency' => 'gbp',
        'stripe_session_id' => 'cs_1', 'status' => Purchase::PENDING, 'terms_version' => 'v1',
    ]);

    event(new CheckoutStarted($purchase));
    event(new PlayerActive($user));
    PlayTime::fulfil($purchase);

    expect(tally(Stats::CHECKOUT_STARTED, 'day'))->toBe(1)
        ->and(tally(Stats::ACTIVE, '2026-10-01'))->toBe(1)
        ->and(tally(Stats::PURCHASE, 'day'))->toBe(1)
        ->and(tally(Stats::FIRST_PURCHASE, 'day'))->toBe(1);

    $this->artisan('stats:report')->assertSuccessful()->expectsOutputToContain('GBP 1.00');
});

test("mail that Brevo refuses for the server's IP is recognised", function () {
    expect(QueuedMail::isBlockedIp(new RuntimeException('Unrecognised IP address 1.2.3.4')))->toBeTrue()
        ->and(QueuedMail::isBlockedIp(new RuntimeException('Connection timed out')))->toBeFalse();
});

test('public pages can carry search and link preview tags', function () {
    $html = view('game-platform::meta', [
        'meta' => ['title' => 'A <Game>', 'description' => 'Fun.', 'url' => 'https://game.example', 'image' => 'https://game.example/og.png'],
        'genre' => ['Strategy'],
    ])->render();

    expect($html)->toContain('<meta property="og:title" content="A &lt;Game&gt;">')
        ->and($html)->toContain('"genre":["Strategy"]')
        ->and($html)->toContain('<link rel="canonical" href="https://game.example">');
});

test('queued mail keeps trying for twelve hours, further apart each time', function () {
    $mail = new Welcome;
    $this->freezeTime();

    expect($mail->retryUntil()->getTimestamp())->toBe(now()->addHours(12)->getTimestamp())
        ->and($mail->backoff())->toBe([60, 300, 900, 3600, 3 * 3600]);
});
