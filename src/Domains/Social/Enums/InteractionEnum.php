<?php

declare(strict_types=1);

namespace Kanvas\Social\Enums;

use Baka\Contracts\EnumsInterface;

enum InteractionEnum implements EnumsInterface
{
    case LIKE;
    case DISLIKE;
    case FOLLOW;
    case FOLLOWERS;
    case FOLLOWING;
    case SAVE;
    case REACTION;
    case COMMENT;
    case SHARE;
    case MENTION;
    case TAG;
    case REPLY;
    case PIN;
    case VIEW;

    /**
     * Get value.
     *
     * @return mixed
     */
    public function getValue(): mixed
    {
        return match ($this) {
            self::LIKE => 'like',
            self::DISLIKE => 'dislike',
            self::FOLLOW => 'follow',
            self::FOLLOWERS => 'followers',
            self::FOLLOWING => 'following',
            self::SAVE => 'save',
            self::REACTION => 'reaction',
            self::COMMENT => 'comment',
            self::SHARE => 'share',
            self::MENTION => 'mention',
            self::TAG => 'tag',
            self::REPLY => 'reply',
            self::PIN => 'pin',
            self::VIEW => 'view',
        };
    }

    public static function getLikeInteractionEnumValue(bool $is_dislike = false): string
    {
        return $is_dislike ? InteractionEnum::DISLIKE->getValue() : InteractionEnum::LIKE->getValue();
    }
}
