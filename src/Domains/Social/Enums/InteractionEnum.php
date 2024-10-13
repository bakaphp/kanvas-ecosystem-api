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

    // Google Interaction Types
    case SEARCH;
    case VIEW_ITEM;
    case VIEW_ITEM_LIST;
    case VIEW_HOME_PAGE;
    case VIEW_CATEGORY_PAGE;
    case ADD_TO_CART;
    case PURCHASE;
    case MEDIA_PLAY;
    case MEDIA_COMPLETE;

    /**
     * Get value.
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
            self::SEARCH => 'search',
            self::VIEW_ITEM => 'view-item',
            self::VIEW_ITEM_LIST => 'view-item-list',
            self::VIEW_HOME_PAGE => 'view-home-page',
            self::VIEW_CATEGORY_PAGE => 'view-category-page',
            self::ADD_TO_CART => 'add-to-cart',
            self::PURCHASE => 'purchase',
            self::MEDIA_PLAY => 'media-play',
            self::MEDIA_COMPLETE => 'media-complete',
        };
    }

    /**
     * Get Like/Dislike Interaction Enum Value
     */
    public static function getLikeInteractionEnumValue(bool $isDislike = false): string
    {
        return $isDislike ? InteractionEnum::DISLIKE->getValue() : InteractionEnum::LIKE->getValue();
    }
}