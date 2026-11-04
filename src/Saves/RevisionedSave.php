<?php

namespace GamePlatform\Saves;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Keep a save the game sent, as the next revision. Refused (null) if the game was saved
 * from somewhere else (another tab or device) since the revision the save carries on
 * from, so one copy never silently overwrites another. Games extend this to find their
 * save row and to act on what was saved.
 *
 * @template TGame of Model
 * @template TState of Model
 */
abstract class RevisionedSave
{
    /**
     * The game's save row, locked for the update (a new, unsaved one if it has none).
     *
     * @param  TGame  $game
     * @return TState
     */
    abstract protected function lockedState(Model $game): Model;

    /**
     * Everything else that follows from the save, inside the same transaction.
     *
     * @param  TGame  $game
     * @param  array<string, mixed>  $data
     * @param  TState  $state
     */
    protected function saved(Model $game, array $data, Model $state): void {}

    /**
     * @param  TGame  $game
     * @param  array<string, mixed>  $data
     * @return TState|null
     */
    public function handle(Model $game, array $data, ?int $baseRevision = null): ?Model
    {
        return DB::transaction(function () use ($game, $data, $baseRevision): ?Model {
            $state = $this->lockedState($game);

            if ($baseRevision !== null && $baseRevision !== (int) ($state->getAttribute('revision') ?? 0)) {
                return null;
            }

            if (! method_exists($state, 'setData')) {
                throw new \LogicException($state::class.' must use '.StoresRevisions::class.'.');
            }
            $state->setData($data);
            $state->save();

            $this->saved($game, $data, $state);

            return $state;
        });
    }
}
