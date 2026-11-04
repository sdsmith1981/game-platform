<?php

namespace GamePlatform\Billing;

use GamePlatform\Billing\Events\PlayerActive;
use GamePlatform\Billing\Events\PurchaseFulfilled;
use GamePlatform\Platform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * How much a player may still play: the free allowance plus what they've bought,
 * less what they've used.
 *
 * Everything on sale adds play-time. Anyone still holding an old unlimited pass
 * (`unlimited_until`) plays without using play-time until it ends.
 */
final class PlayTime
{
    /** The signed-in player, which the app's user model must make one (see Player). */
    public static function player(mixed $user): Model&Player
    {
        abort_unless($user instanceof Model && $user instanceof Player, 403, 'The user model must implement '.Player::class.'.');

        return $user;
    }

    public static function isUnlimited(Player $player): bool
    {
        return $player->unlimitedUntil()?->isFuture() ?? false;
    }

    /** Seconds of play-time left, ignoring any old unlimited pass. */
    public static function remaining(Player $player): int
    {
        return max(0, Config::integer('billing.free_seconds') + $player->playSecondsBought() - $player->playSecondsUsed());
    }

    /**
     * Offline play is for players who have bought play-time (or held the old unlimited
     * pass). Time played offline isn't counted.
     */
    public static function canPlayOffline(Player $player): bool
    {
        return $player->playSecondsBought() > 0 || $player->unlimitedUntil() !== null;
    }

    public static function canPlay(Player $player): bool
    {
        return self::isUnlimited($player) || self::remaining($player) > 0;
    }

    /**
     * What the game and the billing page show. `free` is true while what's being played
     * uses no play-time (see FreePlay), so it can be played whatever is left.
     *
     * @return array{canPlay: bool, free: bool, unlimited: bool, unlimitedUntil: string|null, remainingSeconds: int, usedSeconds: int, freeSeconds: int, warnSeconds: int, heartbeatSeconds: int}
     */
    public static function status(Player $player, bool $free = false): array
    {
        $unlimited = self::isUnlimited($player);

        return [
            'canPlay' => $free || self::canPlay($player),
            'free' => $free,
            'unlimited' => $unlimited,
            'unlimitedUntil' => $unlimited ? $player->unlimitedUntil()?->toIso8601String() : null,
            'remainingSeconds' => self::remaining($player),
            'usedSeconds' => $player->playSecondsUsed(),
            'freeSeconds' => Config::integer('billing.free_seconds'),
            'warnSeconds' => Config::integer('billing.warn_seconds'),
            'heartbeatSeconds' => Config::integer('billing.heartbeat_seconds'),
        ];
    }

    /**
     * The game checked in while being played: count the time since it last did. The
     * first check-in after a break (`resumed`), or one after a long gap, counts nothing,
     * so time spent away or paused is free. The server's clock does the measuring.
     */
    public static function record(Model&Player $player, bool $resumed, ?FreePlaySession $free = null): void
    {
        $now = now();
        $last = $player->lastPlayedAt();
        $gap = $last === null ? null : (int) $last->diffInSeconds($now, true);

        if (! $resumed && $gap !== null && $gap <= Config::integer('billing.max_heartbeat_gap')) {
            // Free play counts toward its own limit instead.
            if ($free !== null) {
                $free->addFreeSeconds($gap);
            } elseif (! self::isUnlimited($player)) {
                $player->setAttribute('play_seconds_used', $player->playSecondsUsed() + $gap);
            }
        }

        $firstToday = $last === null || ! $last->isSameDay($now);

        $player->setAttribute('last_played_at', $now);
        $player->save();

        if ($firstToday) {
            event(new PlayerActive($player));
        }
    }

    /**
     * Give the player what they paid for, once: safe to call again for the same purchase.
     * The first time, PurchaseFulfilled is fired (for a receipt, say).
     */
    public static function fulfil(Purchase $purchase): bool
    {
        $first = false;
        $usedSeconds = 0;
        $fulfilled = DB::transaction(function () use ($purchase, &$first, &$usedSeconds): bool {
            /** @var Purchase $locked */
            $locked = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            if ($locked->status === Purchase::PAID) {
                return false;
            }

            $player = Platform::userModel()::query()->lockForUpdate()->findOrFail($locked->user_id);
            if (! $player instanceof Player) {
                throw new \LogicException('The user model must implement '.Player::class.'.');
            }
            $product = Config::array("billing.products.{$locked->product}");

            $first = ! $player->purchases()->where('status', Purchase::PAID)->exists();
            $usedSeconds = $player->playSecondsUsed();
            $player->setAttribute('play_seconds_bought', $player->playSecondsBought() + (int) $product['seconds']);
            $player->save();

            $locked->update(['status' => Purchase::PAID, 'paid_at' => now()]);
            $purchase->refresh();

            return true;
        });

        if ($fulfilled) {
            // A failing listener (a receipt that can't be queued) mustn't undo the
            // payment, or fail Stripe's webhook.
            try {
                event(new PurchaseFulfilled($purchase, $first, $usedSeconds));
            } catch (Throwable $e) {
                Log::error('A listener failed after a payment', ['purchase' => $purchase->id, 'error' => $e->getMessage()]);
            }
        }

        return $fulfilled;
    }

    /**
     * What's on sale, for buying forms.
     *
     * @return list<array{id: string, name: string, description: string, price: int}>
     */
    public static function products(): array
    {
        $products = [];

        foreach (Config::array('billing.products') as $id => $product) {
            $products[] = [
                'id' => (string) $id,
                'name' => (string) $product['name'],
                'description' => (string) $product['description'],
                'price' => (int) $product['price'],
            ];
        }

        return $products;
    }
}
