<?php

namespace GamePlatform\Billing;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * For the app's user model (which implements Player): play-time used and bought, and
 * the purchases that bought it. The columns come from the package's play-time migration.
 *
 * @property int $play_seconds_used
 * @property int $play_seconds_bought
 * @property CarbonInterface|null $unlimited_until
 * @property CarbonInterface|null $last_played_at
 */
trait HasPlayTime
{
    public function initializeHasPlayTime(): void
    {
        $this->mergeCasts([
            'play_seconds_used' => 'integer',
            'play_seconds_bought' => 'integer',
            'unlimited_until' => 'immutable_datetime',
            'last_played_at' => 'immutable_datetime',
        ]);
    }

    public function playSecondsUsed(): int
    {
        return (int) $this->getAttribute('play_seconds_used');
    }

    public function playSecondsBought(): int
    {
        return (int) $this->getAttribute('play_seconds_bought');
    }

    public function unlimitedUntil(): ?CarbonInterface
    {
        $until = $this->getAttribute('unlimited_until');

        return $until instanceof CarbonInterface ? $until : null;
    }

    public function lastPlayedAt(): ?CarbonInterface
    {
        $at = $this->getAttribute('last_played_at');

        return $at instanceof CarbonInterface ? $at : null;
    }

    /**
     * @return HasMany<Purchase, $this>
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'user_id');
    }
}
