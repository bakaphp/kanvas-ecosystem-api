<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\DataTransferObject;

use Kanvas\Intelligence\FollowUp\Enums\FollowUpOutcomeKindEnum;
use Spatie\LaravelData\Data;

/**
 * Return type for FollowUpLeadAction. Use the static factories — don't
 * construct directly. Five mutually exclusive kinds — see the enum.
 */
class FollowUpOutcome extends Data
{
    public function __construct(
        public readonly FollowUpOutcomeKindEnum $kind,
        public readonly ?string $reason = null,
        public readonly ?string $message = null,
    ) {
    }

    public static function sent(?string $message = null): self
    {
        return new self(kind: FollowUpOutcomeKindEnum::SENT, message: $message);
    }

    public static function skipped(string $reason): self
    {
        return new self(kind: FollowUpOutcomeKindEnum::SKIPPED, reason: $reason);
    }

    public static function exhausted(string $reason): self
    {
        return new self(kind: FollowUpOutcomeKindEnum::EXHAUSTED, reason: $reason);
    }

    public static function completed(): self
    {
        return new self(kind: FollowUpOutcomeKindEnum::COMPLETED);
    }

    public static function error(string $reason): self
    {
        return new self(kind: FollowUpOutcomeKindEnum::ERROR, reason: $reason);
    }

    public function wasSent(): bool
    {
        return $this->kind === FollowUpOutcomeKindEnum::SENT;
    }
}
