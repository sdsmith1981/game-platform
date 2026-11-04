<?php

namespace GamePlatform\Billing\Events;

use GamePlatform\Billing\Purchase;

/**
 * A payment went through and its play-time was added: once per purchase. Listen for it
 * to send a receipt. A listener that throws is logged, never undoing the payment.
 */
class PurchaseFulfilled
{
    public function __construct(
        public Purchase $purchase,
        /** The player's first purchase. */
        public bool $first,
        /** Play-time the player had used before paying. */
        public int $usedSecondsBefore,
    ) {}
}
