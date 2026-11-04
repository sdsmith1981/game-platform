<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A stand-in for a game, for the package's tests. */
class Game extends Model
{
    protected $guarded = [];

    /**
     * @return HasOne<GameState, $this>
     */
    public function state(): HasOne
    {
        return $this->hasOne(GameState::class);
    }
}
