<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Enums;

/**
 * Provider-neutral e-CF lifecycle. Each provider maps its own codes onto these (plan §6.0).
 */
enum EcfStatusEnum: string
{
    case WAITING = 'waiting';
    case ACCEPTED = 'accepted';
    case ACCEPTED_WITH_OBSERVATIONS = 'accepted_with_observations';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public function isFinal(): bool
    {
        return $this !== self::WAITING;
    }

    public function isAccepted(): bool
    {
        return $this === self::ACCEPTED || $this === self::ACCEPTED_WITH_OBSERVATIONS;
    }
}
