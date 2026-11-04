<?php

namespace GamePlatform;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Config;

/** Settings the whole package shares. */
final class Platform
{
    /** @return class-string<Authenticatable> */
    public static function userModel(): string
    {
        /** @var class-string<Authenticatable> */
        return Config::string('game-platform.user_model');
    }
}
