<?php

namespace GamePlatform\Social;

use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\User as SocialUser;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Sign in, or sign up, with Google, Facebook or GitHub.
 *
 * A player is matched by the provider's account first, then by email address, so
 * someone who signed up with a password can also sign in with Google using the same
 * address. Two-factor authentication still applies: a player who has it turned on is
 * asked for their code before they're signed in.
 */
class SocialLoginController extends Controller
{
    public function redirect(string $provider): SymfonyRedirect
    {
        abort_unless(SocialLogin::enabled($provider), 404);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(SocialLogin::enabled($provider), 404);

        $name = SocialLogin::name($provider);

        try {
            $social = Socialite::driver($provider)->user();
        } catch (Throwable $e) {
            // Usually the player cancelled, or came back to an old tab.
            Log::info("{$name} sign-in didn't complete.", ['error' => $e->getMessage()]);

            return self::backToLogin("Signing in with {$name} didn't work. Please try again.");
        }

        $user = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_id', (string) $social->getId())
            ->first()?->user;

        if (! $user) {
            if (! SocialLogin::emailConfirmed($provider, $social)) {
                return self::backToLogin(
                    "{$name} didn't give us a confirmed email address, so we can't sign you in with it. Please sign up with your email address instead.",
                );
            }

            $user = self::findOrCreateUser($provider, $social);
        }

        if (method_exists($user, 'hasEnabledTwoFactorAuthentication') && $user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => true]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(Config::string('fortify.home', '/'));
    }

    /** The player with the provider's email address, or a new one, now linked to the account. */
    private static function findOrCreateUser(string $provider, SocialUser $social): Authenticatable
    {
        return DB::transaction(function () use ($provider, $social) {
            $model = SocialLogin::userModel();
            $email = (string) $social->getEmail();
            $user = $model::query()->where('email', $email)->first();

            if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
                // The provider has shown the player owns this address, but whoever signed
                // up with it never did. Their password might not be the player's, so it goes:
                // the player can set one with "Forgot your password?".
                $user->forceFill(['password' => null, 'email_verified_at' => now()])->save();
            }

            if (! $user) {
                $user = $model::query()->create([
                    'name' => $social->getName() ?: $social->getNickname() ?: strstr($email, '@', true),
                    'email' => $email,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();

                event(new SocialUserCreated($user, $provider));
                event(new Registered($user));
            }

            SocialAccount::query()->updateOrCreate(
                ['user_id' => $user->getKey(), 'provider' => $provider],
                ['provider_id' => (string) $social->getId()],
            );

            return $user;
        });
    }

    private static function backToLogin(string $message): RedirectResponse
    {
        return redirect()->route('login')->with('status', $message);
    }
}
