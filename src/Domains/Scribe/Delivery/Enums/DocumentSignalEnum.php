<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Enums;

/**
 * Customer reactions to a shared document that the AR agent has to act on.
 */
enum DocumentSignalEnum: string
{
    case QUOTE_DECLINED = 'quote_declined';
    case QUOTE_OPENED_NOT_ANSWERED = 'quote_opened_not_answered';
    case NEVER_OPENED = 'never_opened';
    case DUNNING_ESCALATED = 'dunning_escalated';
}
