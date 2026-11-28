<?php

declare(strict_types=1);

use Kanvas\Notifications\Mailings\Audiences\AllAppUsersAudience;
use Kanvas\Souk\Mailings\Audiences\ActiveUsersAudience;

return [
    'audiences' => [
        'active_users' => ActiveUsersAudience::class,
        'app_users' => AllAppUsersAudience::class,
    ],
];
