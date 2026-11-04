<?php

namespace GamePlatform;

use DateTimeInterface;
use GamePlatform\Billing\Events\CheckoutStarted;
use GamePlatform\Billing\Events\PlayerActive;
use GamePlatform\Billing\Events\PurchaseFulfilled;
use GamePlatform\Billing\FreePlay;
use GamePlatform\Billing\NoFreePlay;
use GamePlatform\Console\ResendFeedback;
use GamePlatform\Console\StatsReport;
use GamePlatform\Ops\FeedbackContext;
use GamePlatform\Ops\NoFeedbackContext;
use GamePlatform\Ops\Stats;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class GamePlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/game-platform.php', 'game-platform');
        $this->mergeConfigFrom(__DIR__.'/../config/billing.php', 'billing');

        // Games bind their own to make some play free (a tutorial, say).
        $this->app->bindIf(FreePlay::class, NoFreePlay::class);
        // Games bind their own to add their game's details to feedback.
        $this->app->bindIf(FeedbackContext::class, NoFeedbackContext::class);
    }

    public function boot(): void
    {
        if (Config::boolean('game-platform.social.routes', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/social.php');
        }

        if (Config::boolean('billing.routes', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/billing.php');
        }

        if (Config::boolean('game-platform.ops.routes', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/ops.php');
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'game-platform');

        if (Config::boolean('game-platform.stats.billing', true)) {
            $this->countBilling();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([StatsReport::class, ResendFeedback::class]);

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

    /** Count checkouts, payments and players coming back (see Stats). */
    private function countBilling(): void
    {
        Event::listen(fn (CheckoutStarted $event) => Stats::record(Stats::CHECKOUT_STARTED, $event->purchase->product));

        Event::listen(function (PlayerActive $event): void {
            $created = $event->player->getAttribute('created_at');
            Stats::record(Stats::ACTIVE, $created instanceof DateTimeInterface ? $created->format('Y-m-d') : '');
        });

        Event::listen(function (PurchaseFulfilled $event): void {
            $purchase = $event->purchase;
            Stats::record(Stats::PURCHASE, $purchase->product, $purchase->amount);
            if ($event->first) {
                Stats::record(Stats::FIRST_PURCHASE, $purchase->product, $event->usedSecondsBefore);
            }
        });
    }
}
