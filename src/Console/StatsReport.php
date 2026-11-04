<?php

namespace GamePlatform\Console;

use GamePlatform\Ops\Stats;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * A summary of the anonymous daily counts kept by GamePlatform\Ops\Stats: how many sign
 * up, start games, come back, and buy play-time.
 */
#[Signature('stats:report {--days=28 : How many days back to look, today included}')]
#[Description('Show sign-ups, returning players and sales from the daily stats')]
class StatsReport extends Command
{
    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $today = now()->startOfDay();
        $from = $today->copy()->subDays($days - 1);

        /** @var Collection<int, object{date: string, metric: string, dimension: string, tally: int|string, amount: int|string}> $rows */
        $rows = DB::table('daily_stats')->where('date', '>=', $from->toDateString())->get();
        $sum = fn (string $metric, string $column = 'tally') => (int) $rows->where('metric', $metric)->sum($column);

        $checkouts = $sum(Stats::CHECKOUT_STARTED);
        $purchases = $sum(Stats::PURCHASE);
        $firstPurchases = $sum(Stats::FIRST_PURCHASE);

        $this->info("The last {$days} days, from {$from->toDateString()}");
        $this->table(['', ''], [
            ['Sign-ups', $sum(Stats::SIGNUP)],
            ['Games started', $sum(Stats::GAME_STARTED)],
            ['Players a day, on average', number_format($sum(Stats::ACTIVE) / $days, 1)],
            ['Came back the next day', $this->returned($rows, 1)],
            ['Came back a week later', $this->returned($rows, 7)],
            ['Checkouts started', $checkouts],
            ['Payments', $purchases],
            ['Checkouts that were paid', $checkouts > 0 ? $this->percent($purchases / $checkouts) : '–'],
            ['Taken', strtoupper(Config::string('billing.currency')).' '.number_format($sum(Stats::PURCHASE, 'amount') / 100, 2)],
            ['First payments', $firstPurchases],
            ['Play before a first payment, on average', $firstPurchases > 0
                ? number_format($sum(Stats::FIRST_PURCHASE, 'amount') / $firstPurchases / 3600, 1).' hours'
                : '–'],
        ]);

        return self::SUCCESS;
    }

    /**
     * Of those who signed up in the period, early enough to have had the chance, the
     * share who played again `$after` days later.
     *
     * @param  Collection<int, object{date: string, metric: string, dimension: string, tally: int|string, amount: int|string}>  $rows
     */
    private function returned(Collection $rows, int $after): string
    {
        $latest = now()->subDays($after)->toDateString();
        $joined = 0;
        $back = 0;

        foreach ($rows->where('metric', Stats::SIGNUP) as $signups) {
            $cohort = substr($signups->date, 0, 10);
            if ($cohort > $latest) {
                continue;
            }
            $joined += (int) $signups->tally;
            $day = now()->parse($cohort)->addDays($after)->toDateString();
            $back += (int) $rows
                ->where('metric', Stats::ACTIVE)
                ->where('dimension', $cohort)
                ->filter(fn (object $row) => substr($row->date, 0, 10) === $day)
                ->sum('tally');
        }

        return $joined > 0 ? $this->percent($back / $joined)." of {$joined}" : '–';
    }

    private function percent(float $share): string
    {
        return round($share * 100).'%';
    }
}
