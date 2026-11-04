<?php

namespace GamePlatform\Http\Middleware;

use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Laravel's "confirm your password" step, skipped for players who signed up with
 * Google, Facebook or GitHub and haven't set a password: they have none to confirm,
 * and would otherwise be locked out of their security settings.
 *
 * Use it in place of Laravel's: $middleware->alias(['password.confirm' => RequirePasswordIfSet::class]).
 */
class RequirePasswordIfSet extends RequirePassword
{
    /**
     * @param  Request  $request
     * @param  int|null  $passwordTimeoutSeconds
     */
    protected function shouldConfirmPassword($request, $passwordTimeoutSeconds = null): bool
    {
        $user = $request->user();
        if ($user instanceof Model && $user->getAttribute('password') === null) {
            return false;
        }

        return parent::shouldConfirmPassword($request, $passwordTimeoutSeconds);
    }
}
