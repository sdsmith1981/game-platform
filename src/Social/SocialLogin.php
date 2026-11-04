<?php

namespace GamePlatform\Social;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Config;
use Laravel\Socialite\Contracts\User as SocialUser;
use Laravel\Socialite\Two\User as OAuthUser;

/**
 * The accounts players can sign in with. A provider is offered only once its client ID
 * and secret are set in config/services.php, so each can be switched on separately.
 */
class SocialLogin
{
    /** @return array<string, string> provider => its name on the button */
    public static function providers(): array
    {
        /** @var array<string, string> */
        return Config::array('game-platform.social.providers', []);
    }

    public static function name(string $provider): string
    {
        return self::providers()[$provider] ?? ucfirst($provider);
    }

    public static function enabled(string $provider): bool
    {
        return isset(self::providers()[$provider])
            && filled(Config::get("services.{$provider}.client_id"))
            && filled(Config::get("services.{$provider}.client_secret"));
    }

    /**
     * The providers to show on the log in and sign up pages.
     *
     * @return list<array{id: string, name: string}>
     */
    public static function available(): array
    {
        $available = [];
        foreach (self::providers() as $id => $name) {
            if (self::enabled($id)) {
                $available[] = ['id' => $id, 'name' => $name];
            }
        }

        return $available;
    }

    /**
     * Whether the provider has confirmed the player owns the email address it gave. Only
     * then can it be used to make an account or sign in to one that has that address.
     * Google says so per account; Facebook only gives out confirmed addresses, and
     * Socialite only takes GitHub's primary, verified address.
     */
    public static function emailConfirmed(string $provider, SocialUser $user): bool
    {
        if (blank($user->getEmail())) {
            return false;
        }

        if ($provider === 'google') {
            return $user instanceof OAuthUser && ($user->getRaw()['email_verified'] ?? false) === true;
        }

        return true;
    }

    /** @return class-string<Authenticatable> */
    public static function userModel(): string
    {
        /** @var class-string<Authenticatable> */
        return Config::string('game-platform.user_model');
    }
}
