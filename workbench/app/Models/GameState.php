<?php

namespace Workbench\App\Models;

use GamePlatform\Saves\StoresRevisions;
use Illuminate\Database\Eloquent\Model;

/**
 * A stand-in for a game's save, for the package's tests.
 *
 * @property int $game_id
 * @property int $revision
 * @property string|null $path
 * @property string|null $state
 */
class GameState extends Model
{
    use StoresRevisions;

    protected $guarded = [];

    protected function saveFolderId(): int
    {
        return $this->game_id;
    }
}
