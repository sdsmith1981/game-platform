<?php

return [
    // The app's user model. It should use GamePlatform\Social\HasSocialAccounts.
    'user_model' => env('GAME_PLATFORM_USER_MODEL', 'App\\Models\\User'),

    'social' => [
        // Register the /auth/{provider}/redirect and /callback routes.
        'routes' => true,

        // provider => its name on the button. Each is offered only once its client ID and
        // secret are set in config/services.php, so each can be switched on separately.
        'providers' => [
            'google' => 'Google',
            'facebook' => 'Facebook',
            'github' => 'GitHub',
        ],
    ],
];
