<?php

namespace Workbench\App\Models;

use GamePlatform\Billing\HasPlayTime;
use GamePlatform\Billing\Player;
use GamePlatform\Social\HasSocialAccounts;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/** A stand-in for a game's user model, for the package's tests. */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, Player
{
    use HasPlayTime;
    use HasSocialAccounts;
    use TwoFactorAuthenticatable;

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }
}
