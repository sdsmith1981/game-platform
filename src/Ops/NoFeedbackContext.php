<?php

namespace GamePlatform\Ops;

use Illuminate\Database\Eloquent\Model;

/** The default: feedback is about the game as a whole, not one save. */
class NoFeedbackContext implements FeedbackContext
{
    public function game(Model $user, string $game): ?Model
    {
        return null;
    }

    public function details(?Model $game): array
    {
        return [];
    }

    public function snapshot(Model $game): ?string
    {
        return null;
    }
}
