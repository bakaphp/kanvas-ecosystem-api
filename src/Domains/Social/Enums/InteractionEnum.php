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

    /**
     * Get Like/Dislike Interacition Enum Value
     * 
     * @param bool $isDislike
     * 
     * @return string
     */
    public static function getLikeInteractionEnumValue(bool $isDislike = false): string
    {
        return $isDislike ? InteractionEnum::DISLIKE->getValue() : InteractionEnum::LIKE->getValue();
    }
}
