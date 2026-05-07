<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Enums;

enum SelectionStrategy: string
{
    case FIRST_MATCH = 'first_match';
    case HIGHEST_PRIORITY = 'highest_priority';
    case USER_CHOICE = 'user_choice';
    case ALL_MATCHING = 'all_matching';
}
