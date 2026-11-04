<?php

namespace GamePlatform\Social;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A Google, Facebook or GitHub account a player signs in with.
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $provider_id
 * @property-read Authenticatable|null $user
 */
#[Fillable(['user_id', 'provider', 'provider_id'])]
class SocialAccount extends Model
{
    /**
     * @return BelongsTo<Authenticatable, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(SocialLogin::userModel(), 'user_id');
    }
}
