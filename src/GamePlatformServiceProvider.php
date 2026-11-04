<?php

namespace GamePlatform;

use GamePlatform\Billing\FreePlay;
use GamePlatform\Billing\NoFreePlay;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class GamePlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/game-platform.php', 'game-platform');
        $this->mergeConfigFrom(__DIR__.'/../config/billing.php', 'billing');

        // Games bind their own to make some play free (a tutorial, say).
        $this->app->bindIf(FreePlay::class, NoFreePlay::class);
    }

    public function boot(): void
    {
        if (Config::boolean('game-platform.social.routes', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/social.php');
        }

        if (Config::boolean('billing.routes', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/billing.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/game-platform.php' => config_path('game-platform.php'),
                __DIR__.'/../config/billing.php' => config_path('billing.php'),
            ], 'game-platform-config');

            // Published rather than loaded, so apps that already have these tables keep
            // their own migrations.
            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'game-platform-migrations');
        }
    }
}
