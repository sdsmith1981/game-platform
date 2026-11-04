<?php

namespace GamePlatform\Billing\Events;

use GamePlatform\Billing\Purchase;

/** A player went to Stripe to pay. */
class CheckoutStarted
{
    public function __construct(public Purchase $purchase) {}
}
