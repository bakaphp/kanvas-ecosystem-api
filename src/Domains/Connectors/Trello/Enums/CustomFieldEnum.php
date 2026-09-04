<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Trello\Enums;

/**
 * Entity-level linkage back to Trello. Set on whatever Kanvas entity a rule created the card for,
 * so a re-run of the activity updates the existing card instead of creating a duplicate — the same
 * idempotency shape `PushMessageToWordPressAction` uses for its WordPress post id.
 */
enum CustomFieldEnum: string
{
    case TRELLO_CARD_ID = 'TRELLO_CARD_ID';
    case TRELLO_BOARD_ID = 'TRELLO_BOARD_ID';
    case TRELLO_LIST_ID = 'TRELLO_LIST_ID';
}
