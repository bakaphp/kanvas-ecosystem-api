<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Enums;

use Kanvas\Connectors\WaSender\Traits\ParsesEnumValueTrait;
use Override;

/**
 * What a 1:1 conversation with the connected number is. `lead` is the historical behavior — every
 * DM opens a Lead with stakeholder notifications. `assistant` files the conversation against the
 * Channel and runs the agent, CRM untouched.
 */
enum DirectConversationModeEnum: string
{
    use ParsesEnumValueTrait;

    case LEAD = 'lead';
    case ASSISTANT = 'assistant';

    #[Override]
    protected static function fallback(): self
    {
        return self::LEAD;
    }
}
