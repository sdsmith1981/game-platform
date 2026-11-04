<?php

use GamePlatform\Http\Middleware\RequirePasswordIfSet;
use GamePlatform\Social\SocialAccount;
use GamePlatform\Social\SocialLogin;
use GamePlatform\Social\SocialUserCreated;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OAuthUser;
use Workbench\App\Models\User;

/** @param  array<string, mixed>  $raw */
function socialUser(string $id, ?string $email, string $name = 'Sam Signal', array $raw = []): OAuthUser
{
    return (new OAuthUser)->setRaw($raw)->map([
        'id' => $id,
        'name' => $name,
        'email' => $email,
        'nickname' => null,
    ]);
}

function providerReturns(OAuthUser $user): void
{
    Socialite::shouldReceive('driver->user')->andReturn($user);
}

function makeUser(array $attributes = []): User
{
    $user = new User;
    $user->forceFill([
        'name' => 'Pat Player',
        'email' => 'pat@example.com',
        'password' => 'password',
        'email_verified_at' => now(),
        ...$attributes,
    ])->save();

    return $user;
}

test('only providers with a client ID and secret are offered', function () {
    Config::set('services.facebook.client_secret', null);

    expect(SocialLogin::available())->toBe([
        ['id' => 'google', 'name' => 'Google'],
        ['id' => 'github', 'name' => 'GitHub'],
    ]);

    $this->get(route('social.redirect', 'facebook'))->assertNotFound();
    $this->get(route('social.redirect', 'myspace'))->assertNotFound();
});

test('games can change the providers on offer', function () {
    Config::set('game-platform.social.providers', ['github' => 'GitHub']);

    expect(SocialLogin::available())->toBe([['id' => 'github', 'name' => 'GitHub']]);
    $this->get(route('social.redirect', 'google'))->assertNotFound();
});

test('the redirect sends the player to the provider', function () {
    $this->get(route('social.redirect', 'google'))
        ->assertRedirectContains('accounts.google.com');
});

test('a new player signs up with Google', function () {
    Event::fake([SocialUserCreated::class, Registered::class]);
    providerReturns(socialUser('g-1', 'sam@example.com', raw: ['email_verified' => true]));

    $this->get(route('social.callback', 'google'))->assertRedirect('/dashboard');

    $user = User::query()->where('email', 'sam@example.com')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Sam Signal')
        ->and($user->password)->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->socialAccounts()->sole()->only('provider', 'provider_id'))
        ->toBe(['provider' => 'google', 'provider_id' => 'g-1']);
    Event::assertDispatched(SocialUserCreated::class, fn ($e) => $e->user->is($user) && $e->provider === 'google');
    Event::assertDispatched(Registered::class);
});

test('a returning player is found by their provider account, even if their email has changed', function () {
    $user = makeUser(['email' => 'old@example.com']);
    $user->socialAccounts()->create(['provider' => 'github', 'provider_id' => '42']);

    providerReturns(socialUser('42', 'new@example.com'));

    $this->get(route('social.callback', 'github'))->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1);
});

test('a player who signed up with a password can sign in with Facebook at the same address', function () {
    $user = makeUser(['email' => 'sam@example.com']);
    $password = $user->password;

    providerReturns(socialUser('fb-7', 'sam@example.com'));

    $this->get(route('social.callback', 'facebook'))->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->password)->toBe($password)
        ->and($user->socialAccounts()->sole()->provider)->toBe('facebook');
});

test("an unconfirmed account's password is dropped when its address is proven another way", function () {
    $user = makeUser(['email' => 'sam@example.com', 'email_verified_at' => null]);

    providerReturns(socialUser('g-1', 'sam@example.com', raw: ['email_verified' => true]));

    $this->get(route('social.callback', 'google'));
    $user->refresh();
    expect($user->password)->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeTrue();
});

test('a Google address Google has not confirmed cannot sign in or sign up', function () {
    $user = makeUser(['email' => 'sam@example.com']);

    providerReturns(socialUser('g-1', 'sam@example.com', raw: ['email_verified' => false]));

    $this->get(route('social.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status');
    $this->assertGuest();
    expect($user->socialAccounts()->count())->toBe(0);
});

test('a provider that gives no email address cannot sign up', function () {
    providerReturns(socialUser('fb-7', null));

    $this->get(route('social.callback', 'facebook'))->assertRedirect(route('login'));
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('a cancelled sign-in goes back to the log in page', function () {
    Socialite::shouldReceive('driver->user')->andThrow(new RuntimeException('access_denied'));

    $this->get(route('social.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', "Signing in with Google didn't work. Please try again.");
    $this->assertGuest();
});

test('players with two-factor authentication are asked for their code first', function () {
    $user = makeUser([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->socialAccounts()->create(['provider' => 'github', 'provider_id' => '42']);

    providerReturns(socialUser('42', $user->email));

    $this->get(route('social.callback', 'github'))
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('deleting a player deletes their linked accounts', function () {
    $user = makeUser();
    $user->socialAccounts()->create(['provider' => 'google', 'provider_id' => 'g-9']);

    $user->delete();

    expect(SocialAccount::count())->toBe(0);
});

test('players without a password skip the confirm-password step, others do not', function () {
    Route::middleware(['web', 'auth', RequirePasswordIfSet::class])
        ->get('secure', fn () => 'ok');

    $this->actingAs(makeUser(['password' => null]))->get('/secure')->assertOk()->assertSee('ok');

    $this->actingAs(makeUser(['email' => 'other@example.com']))->get('/secure')->assertRedirect();
});
