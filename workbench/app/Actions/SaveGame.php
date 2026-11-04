<?php

namespace Workbench\App\Actions;

use GamePlatform\Saves\RevisionedSave;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Game;
use Workbench\App\Models\GameState;

/**
 * A stand-in for a game's save action, for the package's tests.
 *
 * @extends RevisionedSave<Game, GameState>
 */
class SaveGame extends RevisionedSave
{
    protected function lockedState(Model $game): Model
    {
        return $game->state()->lockForUpdate()->first() ?? $game->state()->make();
    }

    protected function saved(Model $game, array $data, Model $state): void
    {
        $game->update(['day' => $data['day'] ?? 0]);
    }
}
