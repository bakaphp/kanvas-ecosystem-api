<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Enums;

enum EventStatusEnum: string
{
    case DRAFT = 'Draft';
    case SCHEDULED = 'Scheduled';
    case CONFIRMED = 'Confirmed';
    case ACTIVE = 'Active';
    case IN_PROGRESS = 'In Progress';
    case COMPLETED = 'Completed';
    case CANCELLED = 'Cancelled';
    case POSTPONED = 'Postponed';
    case RESCHEDULED = 'Rescheduled';
    case PENDING = 'Pending';
    case DEFAULT = 'Default';

    /**
     * Get all event status values as an array
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get all event status names as an array
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }

    /**
     * Check if a status indicates the event is active
     */
    public function isActive(): bool
    {
        return in_array($this, [
            self::SCHEDULED,
            self::CONFIRMED,
            self::ACTIVE,
            self::IN_PROGRESS,
        ]);
    }

    /**
     * Check if a status indicates the event is finished
     */
    public function isFinished(): bool
    {
        return in_array($this, [
            self::COMPLETED,
            self::CANCELLED,
        ]);
    }

    /**
     * Check if a status indicates the event can be modified
     */
    public function canBeModified(): bool
    {
        return in_array($this, [
            self::DRAFT,
            self::SCHEDULED,
            self::CONFIRMED,
            self::PENDING,
            self::DEFAULT,
        ]);
    }

    /**
     * Check if a status indicates the event can be cancelled
     */
    public function canBeCancelled(): bool
    {
        return ! in_array($this, [
            self::CANCELLED,
            self::COMPLETED,
        ]);
    }

    /**
     * Get the description for the status
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::DRAFT => 'Event is being planned and not yet published',
            self::SCHEDULED => 'Event is scheduled and published',
            self::CONFIRMED => 'Event is confirmed with participants',
            self::ACTIVE => 'Event is currently happening',
            self::IN_PROGRESS => 'Event is in progress',
            self::COMPLETED => 'Event has been completed successfully',
            self::CANCELLED => 'Event has been cancelled',
            self::POSTPONED => 'Event has been postponed to a later date',
            self::RESCHEDULED => 'Event has been rescheduled',
            self::PENDING => 'Event is pending approval or confirmation',
            self::DEFAULT => 'Default event status',
        };
    }

    /**
     * Get status color for UI representation
     */
    public function getColor(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::SCHEDULED => 'blue',
            self::CONFIRMED => 'green',
            self::ACTIVE => 'yellow',
            self::IN_PROGRESS => 'orange',
            self::COMPLETED => 'green',
            self::CANCELLED => 'red',
            self::POSTPONED => 'purple',
            self::RESCHEDULED => 'indigo',
            self::PENDING => 'yellow',
            self::DEFAULT => 'gray',
        };
    }
}
