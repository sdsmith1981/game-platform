<?php

namespace GamePlatform\Ops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * How a game is doing, as plain counts per day: sign-ups, games started, players
 * coming back, checkouts and payments. Nothing here says who did what (no user IDs,
 * emails or IP addresses), and nothing is sent anywhere else. `stats:report` reads it, and
 * the package counts sign-ups' play and payments itself (see GamePlatformServiceProvider).
 *
 * Coming back is counted by sign-up day ("dimension"), once per player per day, so
 * day-1 and day-7 return can be worked out without keeping anyone's history.
 */
final class Stats
{
    public const string SIGNUP = 'signup';

    public const string GAME_STARTED = 'game_started';

    /** A player's first game check-in of the day; dimension is the day they signed up. */
    public const string ACTIVE = 'active';

    public const string CHECKOUT_STARTED = 'checkout_started';

    /** Amount: the price, in the smallest unit of the currency. */
    public const string PURCHASE = 'purchase';

    /** A player's first payment; amount: the seconds of play they'd used before it. */
    public const string FIRST_PURCHASE = 'first_purchase';

    /**
     * Add one to today's count. Never fails the request it's counting: a lost count
     * matters far less than a lost save or payment.
     */
    public static function record(string $metric, string $dimension = '', int $amount = 0): void
    {
        try {
            $row = ['date' => now()->toDateString(), 'metric' => $metric, 'dimension' => $dimension];
            // Make sure today's row exists, then add to it in one statement, so two
            // requests counting at once both count.
            DB::table('daily_stats')->insertOrIgnore($row);
            DB::table('daily_stats')->where($row)->incrementEach(['tally' => 1, 'amount' => max(0, $amount)]);
        } catch (Throwable $e) {
            Log::warning('Could not record a stat', ['metric' => $metric, 'error' => $e->getMessage()]);
        }
    }
}
