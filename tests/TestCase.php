<?php

namespace GamePlatform\Tests;

use GamePlatform\GamePlatformServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Laravel\Fortify\FortifyServiceProvider;
use Laravel\Socialite\SocialiteServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Models\User;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [SocialiteServiceProvider::class, FortifyServiceProvider::class, GamePlatformServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('game-platform.user_model', User::class);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('fortify.home', '/dashboard');
        $app['config']->set('fortify.features', [Features::twoFactorAuthentication()]);

        foreach (['google', 'facebook', 'github'] as $provider) {
            $app['config']->set("services.{$provider}", [
                'client_id' => "{$provider}-id",
                'client_secret' => "{$provider}-secret",
                'redirect' => "/auth/{$provider}/callback",
            ]);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
