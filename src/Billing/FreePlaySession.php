<?php

namespace GamePlatform\Billing;

/** Something being played free. It's told the time played, to run out its own allowance. */
interface FreePlaySession
{
    public function addFreeSeconds(int $seconds): void;
}
