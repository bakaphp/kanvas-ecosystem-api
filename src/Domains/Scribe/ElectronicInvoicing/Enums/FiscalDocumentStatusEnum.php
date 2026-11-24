<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Enums;

/**
 * The fiscal row's lifecycle (plan §5). Providers still answer in EcfStatusEnum; this adds the states only we
 * can know: allocated but unsent, mid-send, and sent without a readable answer.
 */
enum FiscalDocumentStatusEnum: string
{
    case DRAFT = 'draft';
    case SENDING = 'sending';
    case PENDING = 'pending';
    case UNKNOWN = 'unknown';
    case ACCEPTED = 'accepted';
    case ACCEPTED_WITH_OBSERVATIONS = 'accepted_with_observations';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public static function fromAnswer(EcfStatusEnum $status): self
    {
        return match ($status) {
            EcfStatusEnum::WAITING => self::PENDING,
            EcfStatusEnum::ACCEPTED => self::ACCEPTED,
            EcfStatusEnum::ACCEPTED_WITH_OBSERVATIONS => self::ACCEPTED_WITH_OBSERVATIONS,
            EcfStatusEnum::REJECTED => self::REJECTED,
            EcfStatusEnum::CANCELLED => self::CANCELLED,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::ACCEPTED, self::ACCEPTED_WITH_OBSERVATIONS, self::REJECTED, self::CANCELLED], true);
    }

    public function isAccepted(): bool
    {
        return $this === self::ACCEPTED || $this === self::ACCEPTED_WITH_OBSERVATIONS;
    }

    /**
     * Still waiting on the authority: what the poller chases.
     */
    public function isOutstanding(): bool
    {
        return in_array($this, [self::SENDING, self::PENDING, self::UNKNOWN], true);
    }

    /**
     * The provider-facing view the rest of Scribe was written against.
     */
    public function toEcfStatus(): EcfStatusEnum
    {
        return match ($this) {
            self::DRAFT, self::SENDING, self::PENDING, self::UNKNOWN => EcfStatusEnum::WAITING,
            self::ACCEPTED => EcfStatusEnum::ACCEPTED,
            self::ACCEPTED_WITH_OBSERVATIONS => EcfStatusEnum::ACCEPTED_WITH_OBSERVATIONS,
            self::REJECTED => EcfStatusEnum::REJECTED,
            self::CANCELLED => EcfStatusEnum::CANCELLED,
        };
    }
}
