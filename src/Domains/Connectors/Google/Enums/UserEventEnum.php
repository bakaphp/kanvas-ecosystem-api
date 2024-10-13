<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Enums;

use Kanvas\Social\Enums\InteractionEnum;
use Kanvas\Social\Interactions\Models\Interactions;

enum UserEventEnum: string
{
    /**
    * setEventType , types from google
    *
    * - `search`: Search for Documents.
    * - `view-item`: Detailed page view of a Document.
    * - `view-item-list`: View of a panel or ordered list of Documents.
    * - `view-home-page`: View of the home page.
    * - `view-category-page`: View of a category page, e.g., Home > Men > Jeans.
    *
    * Retail-related values:
    *
    * - `add-to-cart`: Add item(s) to cart, e.g., in retail online shopping.
    * - `purchase`: Purchase item(s).
    *
    * Media-related values:
    *
    * - `media-play`: Start/resume watching a video, playing a song, etc.
    * - `media-complete`: Finished or stopped midway through a video, song, etc.
    */

    case SEARCH = 'search';
    case VIEW_ITEM = 'view-item';
    case VIEW_ITEM_LIST = 'view-item-list';
    case VIEW_HOME_PAGE = 'view-home-page';
    case VIEW_CATEGORY_PAGE = 'view-category-page';
    case ADD_TO_CART = 'add-to-cart';
    case PURCHASE = 'purchase';
    case MEDIA_PLAY = 'media-play';
    case MEDIA_COMPLETE = 'media-complete';

    public static function getEventFromInteraction(Interactions $interaction): ?string
    {
        $googleInteraction = $interaction->get(CustomFieldEnum::GOOGLE_INTERACTION_NAME->value) ?? self::convertInteractionToEvent($interaction);

        //check if the value is in the enum on this enum class
        if ($googleInteraction && self::tryFrom($googleInteraction) !== null) {
            return $googleInteraction;
        }

        return null;
    }

    public static function convertInteractionToEvent(Interactions $interaction): string
    {
        return match ($interaction->name) {
            InteractionEnum::LIKE->getValue() => self::VIEW_ITEM->value,
            InteractionEnum::SAVE->getValue() => self::VIEW_ITEM->value,
            InteractionEnum::COMMENT->getValue() => self::VIEW_ITEM->value,
            InteractionEnum::SHARE->getValue() => self::VIEW_ITEM->value,
            InteractionEnum::TAG->getValue() => self::VIEW_ITEM->value,
            InteractionEnum::REPLY->getValue() => self::VIEW_ITEM->value,
            InteractionEnum::PIN->getValue() => self::PURCHASE->value,
            InteractionEnum::VIEW->getValue() => self::VIEW_ITEM->value,
            default => null
        };
    }
}
