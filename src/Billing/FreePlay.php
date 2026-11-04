<?php

namespace GamePlatform\Billing;

use Illuminate\Database\Eloquent\Model;

/**
 * Which play is free: bound by each game. The game's check-in names what's being played
 * (`game`), and if that plays without using play-time (a tutorial, say), this finds it.
 */
interface FreePlay
{
    public function find(Model&Player $player, ?string $game): ?FreePlaySession;
}
