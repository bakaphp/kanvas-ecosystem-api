<?php

declare(strict_types=1);

namespace Kanvas\Connectors\TypeSafe\Enums;

/**
 * How far a single decision has been rolled out from the LLM path to System One.
 *
 * SHADOW runs both and acts on the LLM, so it is safe to turn on for a tenant without telling them.
 * LIVE acts on System One above the decision's confidence threshold and falls back to the LLM below
 * it, or on any error.
 */
enum DecisionModeEnum: string
{
    case OFF = 'off';
    case SHADOW = 'shadow';
    case LIVE = 'live';

    /**
     * A mode we cannot read is OFF, never LIVE: a typo in a tenant's settings blob must not be what
     * hands a decision over to a model nobody has shadow-compared yet.
     */
    public static function fromSetting(mixed $value): self
    {
        return is_string($value)
            ? self::tryFrom(strtolower(trim($value))) ?? self::OFF
            : self::OFF;
    }
}
