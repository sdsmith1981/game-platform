<?php

namespace GamePlatform\Billing;

use Illuminate\Database\Eloquent\Model;

/** The default: everything uses play-time. */
class NoFreePlay implements FreePlay
{
    public function find(Model&Player $player, ?string $game): ?FreePlaySession
    {
        return null;
    }
}
