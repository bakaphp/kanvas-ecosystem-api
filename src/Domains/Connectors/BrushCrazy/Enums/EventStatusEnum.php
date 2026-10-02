<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Enums;

/**
 * BrushCrazy has no status column — `Event::currentStatus()` is a ladder over nullable timestamps,
 * and lessons/workshops only carry `published_at`. These are the Kanvas `event_statuses` rows the
 * connector seeds so both shapes have somewhere to land.
 */
enum EventStatusEnum: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case DEPOSIT_REQUESTED = 'deposit-requested';
    case DEPOSIT_PAID = 'deposit-paid';
    case FINALIZED = 'finalized';
    case PUBLISHED = 'published';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING => 'Pending',
            self::APPROVED => 'Approved',
            self::DEPOSIT_REQUESTED => 'Deposit Requested',
            self::DEPOSIT_PAID => 'Deposit Paid',
            self::FINALIZED => 'Finalized',
            self::PUBLISHED => 'Published',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function isDefault(): bool
    {
        return $this === self::DRAFT;
    }

    /**
     * `EventStatus::canTransitionTo()` matches by name, so these are labels rather than slugs.
     *
     * @return array<int, string>
     */
    public function validTransitions(): array
    {
        return match ($this) {
            self::DRAFT => [self::PENDING->label(), self::PUBLISHED->label(), self::CANCELLED->label()],
            self::PENDING => [self::APPROVED->label(), self::CANCELLED->label()],
            self::APPROVED => [self::DEPOSIT_REQUESTED->label(), self::PUBLISHED->label(), self::CANCELLED->label()],
            self::DEPOSIT_REQUESTED => [self::DEPOSIT_PAID->label(), self::CANCELLED->label()],
            self::DEPOSIT_PAID => [self::FINALIZED->label(), self::CANCELLED->label()],
            self::FINALIZED => [self::PUBLISHED->label(), self::COMPLETED->label(), self::CANCELLED->label()],
            self::PUBLISHED => [self::COMPLETED->label(), self::CANCELLED->label()],
            self::COMPLETED, self::CANCELLED => [],
        };
    }
}
