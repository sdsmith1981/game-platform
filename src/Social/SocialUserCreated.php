<?php

namespace GamePlatform\Social;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A player signed up with Google, Facebook or GitHub. Fired before Laravel's Registered
 * event, so games can set up a new player (mark release notes seen, say) first.
 */
class SocialUserCreated
{
    public function __construct(public Authenticatable $user, public string $provider) {}
}
