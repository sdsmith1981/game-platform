<?php

use GamePlatform\Billing\Events\CheckoutStarted;
use GamePlatform\Billing\Events\PlayerActive;
use GamePlatform\Billing\Events\PurchaseFulfilled;
use GamePlatform\Billing\FreePlay;
use GamePlatform\Billing\FreePlaySession;
use GamePlatform\Billing\Player;
use GamePlatform\Billing\PlayTime;
use GamePlatform\Billing\Purchase;
use GamePlatform\Billing\StripeCheckout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Stripe\Exception\SignatureVerificationException;
use Workbench\App\Models\User;

function player(array $attributes = []): User
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

function purchase(User $user, string $product = 'day', string $session = 'cs_test_1'): Purchase
{
    return $user->purchases()->create([
        'product' => $product,
        'amount' => config("billing.products.$product.price"),
        'currency' => 'gbp',
        'stripe_session_id' => $session,
        'status' => Purchase::PENDING,
        'terms_version' => '2026-10-01',
    ]);
}

test('new players get two hours free', function () {
    $user = player();

    expect(PlayTime::remaining($user))->toBe(7200)
        ->and(PlayTime::canPlay($user))->toBeTrue()
        ->and(PlayTime::canPlayOffline($user))->toBeFalse();

    $user->play_seconds_used = 7200;
    expect(PlayTime::canPlay($user))->toBeFalse();
});

test('only time between check-ins while playing is counted', function () {
    $user = player();
    $this->freezeTime();

    // The first check-in starts the clock.
    $this->actingAs($user)->postJson(route('play-time.store'), ['resumed' => true])
        ->assertOk()
        ->assertJson(['remainingSeconds' => 7200, 'canPlay' => true, 'free' => false]);

    $this->travel(60)->seconds();
    $this->actingAs($user)->postJson(route('play-time.store'))
        ->assertJson(['remainingSeconds' => 7140]);

    // A long gap (tab closed, game paused) isn't counted...
    $this->travel(30)->minutes();
    $this->actingAs($user)->postJson(route('play-time.store'));
    // ...nor is a check-in after a break.
    $this->travel(60)->seconds();
    $this->actingAs($user)->postJson(route('play-time.store'), ['resumed' => true]);

    expect($user->refresh()->play_seconds_used)->toBe(60);
});

test('play-time runs out', function () {
    $this->freezeTime();
    $user = player(['play_seconds_used' => 7180, 'last_played_at' => now()]);
    $this->travel(60)->seconds();

    $this->actingAs($user)->postJson(route('play-time.store'))
        ->assertJson(['canPlay' => false, 'remainingSeconds' => 0]);
});

test("a game's free play counts toward its own limit, not play-time", function () {
    $session = new class implements FreePlaySession
    {
        public int $seconds = 0;

        public function addFreeSeconds(int $seconds): void
        {
            $this->seconds += $seconds;
        }
    };
    $this->app->instance(FreePlay::class, new class($session) implements FreePlay
    {
        public function __construct(private FreePlaySession $session) {}

        public function find(Model&Player $player, ?string $game): ?FreePlaySession
        {
            return $game === 'tutorial' ? $this->session : null;
        }
    });
    $this->freezeTime();
    $user = player(['play_seconds_used' => 7200, 'last_played_at' => now()]);
    $this->travel(60)->seconds();

    $this->actingAs($user)->postJson(route('play-time.store'), ['game' => 'tutorial'])
        ->assertJson(['canPlay' => true, 'free' => true]);
    expect($session->seconds)->toBe(60)
        ->and($user->refresh()->play_seconds_used)->toBe(7200);

    $this->travel(60)->seconds();
    $this->actingAs($user)->postJson(route('play-time.store'), ['game' => 'another'])
        ->assertJson(['canPlay' => false, 'free' => false]);
});

test("the day's first check-in marks the player active", function () {
    Event::fake([PlayerActive::class]);
    $user = player();
    $this->freezeTime();

    PlayTime::record($user, true);
    PlayTime::record($user, false);
    Event::assertDispatchedTimes(PlayerActive::class, 1);

    $this->travel(1)->days();
    PlayTime::record($user, true);
    Event::assertDispatchedTimes(PlayerActive::class, 2);
});

test('a day of play-time adds 24 hours, once', function () {
    Event::fake([PurchaseFulfilled::class]);
    $user = player(['play_seconds_used' => 7200]);
    $purchase = purchase($user);

    expect(PlayTime::fulfil($purchase))->toBeTrue()
        ->and(PlayTime::fulfil($purchase))->toBeFalse();

    $user->refresh();
    expect(PlayTime::remaining($user))->toBe(86400)
        ->and(PlayTime::canPlayOffline($user))->toBeTrue()
        ->and($purchase->refresh()->status)->toBe(Purchase::PAID)
        ->and($purchase->paid_at)->not->toBeNull();
    Event::assertDispatchedTimes(PurchaseFulfilled::class, 1);
    Event::assertDispatched(PurchaseFulfilled::class, fn ($e) => $e->first && $e->usedSecondsBefore === 7200);
});

test('only the first purchase is marked first', function () {
    Event::fake([PurchaseFulfilled::class]);
    $user = player();

    PlayTime::fulfil(purchase($user, 'day', 'cs_a'));
    PlayTime::fulfil(purchase($user, 'day', 'cs_b'));

    Event::assertDispatched(PurchaseFulfilled::class, fn ($e) => $e->purchase->stripe_session_id === 'cs_a' && $e->first);
    Event::assertDispatched(PurchaseFulfilled::class, fn ($e) => $e->purchase->stripe_session_id === 'cs_b' && ! $e->first);
});

test("a listener that fails doesn't stop the payment", function () {
    Event::listen(PurchaseFulfilled::class, fn () => throw new RuntimeException('mail is down'));
    $user = player();

    expect(PlayTime::fulfil(purchase($user, 'month')))->toBeTrue()
        ->and(PlayTime::remaining($user->refresh()))->toBe(7200 + 30 * 86400);
});

test('the month adds 720 hours of play-time, used only while playing', function () {
    $this->freezeTime();
    $user = player(['play_seconds_used' => 7200, 'last_played_at' => now()]);

    PlayTime::fulfil(purchase($user, 'month', 'cs_a'));
    PlayTime::fulfil(purchase($user, 'month', 'cs_b'));
    expect(PlayTime::remaining($user->refresh()))->toBe(2 * 30 * 86400);

    $this->travel(60)->seconds();
    PlayTime::record($user, false);
    expect(PlayTime::remaining($user->refresh()))->toBe(2 * 30 * 86400 - 60);

    $this->travel(40)->days();
    PlayTime::record($user, true);
    expect(PlayTime::remaining($user->refresh()))->toBe(2 * 30 * 86400 - 60);
});

test('an old unlimited pass still lasts until its end date', function () {
    $this->freezeTime();
    $user = player(['play_seconds_used' => 7200, 'last_played_at' => now(), 'unlimited_until' => now()->addDays(5)]);

    $this->travel(60)->seconds();
    PlayTime::record($user, false);
    expect(PlayTime::canPlay($user->refresh()))->toBeTrue()
        ->and($user->play_seconds_used)->toBe(7200)
        ->and(PlayTime::status($user)['unlimited'])->toBeTrue();

    $this->travel(6)->days();
    expect(PlayTime::canPlay($user->refresh()))->toBeFalse();
});

test('checkout needs the terms and the cancellation waiver agreed', function () {
    $user = player();

    $this->actingAs($user)->post(route('checkout.store'), ['product' => 'day'])
        ->assertSessionHasErrors(['terms', 'start_now']);

    $this->actingAs($user)->post(route('checkout.store'), ['product' => 'lifetime', 'terms' => true, 'start_now' => true])
        ->assertSessionHasErrors(['product']);

    expect(Purchase::count())->toBe(0);
});

test('checkout sends the player to Stripe with the purchase recorded', function () {
    Event::fake([CheckoutStarted::class]);
    $user = player();
    $this->mock(StripeCheckout::class)
        ->shouldReceive('create')
        ->once()
        ->withArgs(fn (Purchase $p, User $u, string $success) => $p->amount === 100 && $u->is($user) && $success === route('checkout.success'))
        ->andReturn(['id' => 'cs_test_abc', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_abc']);

    $this->actingAs($user)
        ->post(route('checkout.store'), ['product' => 'day', 'terms' => '1', 'start_now' => '1', 'return_to' => '/games/abc'], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://checkout.stripe.com/c/pay/cs_test_abc');

    $purchase = Purchase::sole();
    expect($purchase->stripe_session_id)->toBe('cs_test_abc')
        ->and($purchase->status)->toBe(Purchase::PENDING)
        ->and($purchase->terms_version)->toBe('2026-10-01');
    Event::assertDispatched(CheckoutStarted::class);
});

test("a checkout Stripe can't start is dropped", function () {
    $this->mock(StripeCheckout::class)->shouldReceive('create')->andThrow(new RuntimeException('down'));

    $this->actingAs(player())->post(route('checkout.store'), ['product' => 'day', 'terms' => '1', 'start_now' => '1'])
        ->assertSessionHasErrors(['product']);

    expect(Purchase::count())->toBe(0);
});

test('coming back from Stripe adds the play-time and returns to the game', function () {
    $user = player();
    purchase($user, 'day', 'cs_test_back');
    $this->mock(StripeCheckout::class)->shouldReceive('isPaid')->with('cs_test_back')->andReturnTrue();

    $this->actingAs($user)
        ->withSession(['checkout_return_to' => '/games/abc'])
        ->get(route('checkout.success', ['session_id' => 'cs_test_back']))
        ->assertRedirect('/games/abc');

    expect($user->refresh()->play_seconds_bought)->toBe(86400);
});

test("players can't claim someone else's payment", function () {
    $owner = player();
    purchase($owner, 'day', 'cs_test_theirs');
    $this->mock(StripeCheckout::class)->shouldNotReceive('isPaid');

    $this->actingAs(player())
        ->get(route('checkout.success', ['session_id' => 'cs_test_theirs']))
        ->assertRedirect(route('play-time.index'));

    expect($owner->refresh()->play_seconds_bought)->toBe(0);
});

test("Stripe's webhook adds the play-time", function () {
    $user = player();
    purchase($user, 'month', 'cs_test_hook');
    $this->mock(StripeCheckout::class)
        ->shouldReceive('paidSessionFromWebhook')
        ->with('{"id":"evt"}', 'sig')
        ->andReturn('cs_test_hook');

    $this->call('POST', route('stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'sig', 'CONTENT_TYPE' => 'application/json'], '{"id":"evt"}')
        ->assertNoContent();

    expect(PlayTime::remaining($user->refresh()))->toBe(7200 + 30 * 86400);
});

test('webhooks without a valid signature are refused', function () {
    $this->mock(StripeCheckout::class)
        ->shouldReceive('paidSessionFromWebhook')
        ->andThrow(new SignatureVerificationException('bad'));

    $this->postJson(route('stripe.webhook'), ['id' => 'evt'])->assertStatus(400);
});

test('the play-time page shows what is left, what is on sale and what was bought', function () {
    $user = player();
    PlayTime::fulfil(purchase($user));

    $this->actingAs($user->refresh())->get(route('play-time.index'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'billing/PlayTime')
        ->assertJsonPath('props.playTime.remainingSeconds', 7200 + 86400)
        ->assertJsonCount(2, 'props.products')
        ->assertJsonPath('props.purchases.0.name', '24 hours of play-time');
});

test('the products are listed for buying forms', function () {
    expect(PlayTime::products())->toBe([
        ['id' => 'day', 'name' => '24 hours of play-time', 'description' => 'A day.', 'price' => 100],
        ['id' => 'month', 'name' => '30 days of play-time', 'description' => 'A month.', 'price' => 1000],
    ]);
});
