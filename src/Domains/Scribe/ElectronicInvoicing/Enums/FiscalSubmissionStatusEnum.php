<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Enums;

enum FiscalSubmissionStatusEnum: string
{
    case SENDING = 'sending';
    case DELIVERED = 'delivered';
    case UNKNOWN = 'unknown';
    case PROVIDER_REJECTED = 'provider_rejected';
    case AUTHORITY_ACCEPTED = 'authority_accepted';
    case AUTHORITY_REJECTED = 'authority_rejected';

    public static function fromAnswer(EcfStatusEnum $status): self
    {
        return match ($status) {
            EcfStatusEnum::ACCEPTED, EcfStatusEnum::ACCEPTED_WITH_OBSERVATIONS => self::AUTHORITY_ACCEPTED,
            EcfStatusEnum::REJECTED => self::AUTHORITY_REJECTED,
            default => self::DELIVERED,
        };
    }

    public function isSettled(): bool
    {
        return in_array($this, [self::PROVIDER_REJECTED, self::AUTHORITY_ACCEPTED, self::AUTHORITY_REJECTED], true);
    }
}
