<?php

/*
| Paying for play-time. Every account gets some free play; after that players buy
| play-time as one-off Stripe payments. Prices are in the currency's smallest unit (pence).
| Each game sets its own products in its config/billing.php.
*/

return [
    // Register the play-time, checkout and Stripe webhook routes.
    'routes' => true,

    // The app's Inertia page for buying play-time.
    'page' => 'billing/PlayTime',

    'currency' => 'gbp',

    // Free play for every account, in seconds of play-time.
    'free_seconds' => 2 * 3600,

    // id => name, description, price, seconds
    'products' => [],

    // The game checks in this often while it's being played...
    'heartbeat_seconds' => 120,
    // ...and gaps longer than this (a closed tab, a pause) aren't counted.
    'max_heartbeat_gap' => 180,

    // Warn the player when this much play-time is left.
    'warn_seconds' => 10 * 60,
];
