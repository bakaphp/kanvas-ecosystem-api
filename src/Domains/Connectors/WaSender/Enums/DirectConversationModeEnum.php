<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Enums;

/**
 * What a 1:1 conversation with the connected number is. `lead` is the historical behavior — every
 * DM opens a Lead with stakeholder notifications. `assistant` files the conversation against the
 * Channel and runs the agent, CRM untouched.
 */
enum DirectConversationModeEnum: string
{
    case LEAD = 'lead';
    case ASSISTANT = 'assistant';

    public static function tryFromValue(mixed $value): self
    {
        return is_string($value) ? self::tryFrom($value) ?? self::LEAD : self::LEAD;
    }
}
