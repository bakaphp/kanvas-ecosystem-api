<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Enums;

enum FeedTagsEnum: string
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

    public function userMotivationLabel(): string
    {
        return match ($this) {
            self::TRENDING => 'Trending',
            self::FOR_YOU => 'For You',
            self::FOLLOWING => 'Following',
            self::CAREER => 'Career',
            self::RELATIONSHIPS => 'Relationships',
            self::GROWTH => 'Personal Growth',
            self::CREATIVITY => 'Creativity & Arts',
            self::HEALTH => 'Health & Wellness',
            self::TECH => 'Technology',
        };
    }

    public static function findByUserMotivationLabel(string $label): ?self
    {
        foreach (self::cases() as $case) {
            if (strcasecmp($case->userMotivationLabel(), $label) === 0) {
                return $case;
            }
        }
        return null;
    }
}
