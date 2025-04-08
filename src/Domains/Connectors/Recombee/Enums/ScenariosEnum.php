<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Enums;

enum ScenariosEnum: string
{
    case USER_FOLLOW_SUGGETIONS_SIMILAR_INTERESTS = 'user-follow-suggestion-similar-interests';
    case STATIC_USERS_RECOMMENDATION = 'static-users-recommendation';
}
