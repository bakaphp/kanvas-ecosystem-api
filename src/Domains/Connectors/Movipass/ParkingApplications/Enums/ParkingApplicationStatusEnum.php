<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\ParkingApplications\Enums;

use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum;

/**
 * Extends what CorporateApplicationStatusEnum already gives (pending / needs_review / approved /
 * rejected) with the two states the parking flow adds on top of it.
 *
 * APPROVED and PUBLISHED are deliberately separate states, not one. Approval is a review decision;
 * publication is the Phase 29 orchestration that creates the product/variants/warehouses and can
 * fail partway and need a retry. Collapsing them into one status would make a failed publish look
 * like an undecided application, sending it back into the review queue instead of the retry path.
 */
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

    /**
     * Still awaiting an action from either side — the applicant (correction) or a reviewer
     * (pending / needs_review).
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::PENDING, self::NEEDS_REVIEW, self::NEEDS_CORRECTION => true,
            default => false,
        };
    }

    /**
     * No further processing happens on the application. APPROVED is intentionally excluded —
     * it still owes the Phase 29 publish orchestration before the parking is live.
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::REJECTED, self::PUBLISHED => true,
            default => false,
        };
    }
}
