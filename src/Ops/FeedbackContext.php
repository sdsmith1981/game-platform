<?php

namespace GamePlatform\Ops;

use Illuminate\Database\Eloquent\Model;

/**
 * What a game adds to a player's feedback: which of their games it's about, details of
 * it, and a copy of its save. Bound by each game; by default there's none.
 */
interface FeedbackContext
{
    /** The player's game the browser named, if it's theirs. */
    public function game(Model $user, string $game): ?Model;

    /**
     * Details of the game for the report (never the player's email address).
     *
     * @return array<string, string|int|bool|null>
     */
    public function details(?Model $game): array;

    /** The game's save, gzipped, kept with the report so the bug can be reproduced. */
    public function snapshot(Model $game): ?string;
}
