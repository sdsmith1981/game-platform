<?php

namespace GamePlatform\Social;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * For the app's user model: the Google, Facebook or GitHub accounts a player signs in with.
 */
trait HasSocialAccounts
{
    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class, 'user_id');
    }
}
