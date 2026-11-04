<?php

namespace GamePlatform\Billing\Events;

use GamePlatform\Billing\Player;
use Illuminate\Database\Eloquent\Model;

/** A player's game checked in for the first time today. */
class PlayerActive
{
    public function __construct(public Model&Player $player) {}
}
