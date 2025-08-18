<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Enums;

enum FeedTabsEnum: string
{
    case TRENDING = 'trending';
    case FOR_YOU = 'for_you';
    case FOLLOWING = 'following';
    case CAREER = 'career';
    case RELATIONSHIPS = 'relationships';
    case GROWTH = 'growth';
    case CREATIVITY = 'creativity';
    case HEALTH = 'health';
    case TECH = 'tech';


    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
