<?php

namespace GamePlatform\Billing;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A player who plays for time: the app's user model, with HasPlayTime.
 */
interface Player
{
    /** Seconds of play-time used. */
    public function playSecondsUsed(): int;

    /** Seconds of play-time bought, on top of the free allowance. */
    public function playSecondsBought(): int;

    /** Plays without using play-time until then (the old unlimited pass). */
    public function unlimitedUntil(): ?CarbonInterface;

    /** When the game last checked in. */
    public function lastPlayedAt(): ?CarbonInterface;

    /** @return HasMany<Purchase, covariant Model> */
    public function purchases(): HasMany;
}
