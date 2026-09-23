<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum;

enum ParkingApplicationStatusEnum: string
{
    case PENDING = 'pending';
    case NEEDS_REVIEW = 'needs_review';
    case NEEDS_CORRECTION = 'needs_correction';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case PUBLISHED = 'published';

    public static function fromCorporate(CorporateApplicationStatusEnum $status): self
    {
        return match ($status) {
            CorporateApplicationStatusEnum::PENDING => self::PENDING,
            CorporateApplicationStatusEnum::NEEDS_REVIEW => self::NEEDS_REVIEW,
            CorporateApplicationStatusEnum::APPROVED => self::APPROVED,
            CorporateApplicationStatusEnum::REJECTED => self::REJECTED,
        };
    }

    public function isOpen(): bool
    {
        return match ($this) {
            self::PENDING, self::NEEDS_REVIEW, self::NEEDS_CORRECTION => true,
            default => false,
        };
    }

    public function isFinal(): bool
    {
        return match ($this) {
            self::REJECTED, self::PUBLISHED => true,
            default => false,
        };
    }
}
