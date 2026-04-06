<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Event\Events\Enums\EmailTemplateEnum;
use Kanvas\Event\Events\Enums\EventReminderStatusEnum;
use Kanvas\Event\Events\Enums\EventStatusEnum;
use Kanvas\Event\Events\Models\EventReminder;
use Kanvas\Event\Events\Models\EventVersion;

class ScheduleEventReminderAction
{
    public function __construct(
        protected EventVersion $eventVersion,
        protected ?int $offsetInMinutes = null,
    ) {
    }

    public function execute(): ?EventReminder
    {
        $this->eventVersion->loadMissing('dates', 'event.eventStatus');

        if ($this->isCancelled()) {
            $this->cancelPendingReminder();

            return null;
        }

        $firstDate = $this->eventVersion->dates->sortBy('event_date')->first();
        if ($firstDate === null) {
            $this->cancelPendingReminder();

            return null;
        }

        $startAt = Carbon::parse($firstDate->event_date->format('Y-m-d') . ' ' . $firstDate->start_time);
        if ($startAt->lessThanOrEqualTo(now())) {
            $this->cancelPendingReminder();

            return null;
        }

        $sendAt = $startAt->copy()->subMinutes($this->getOffsetInMinutes());
        if ($sendAt->lessThan(now())) {
            $sendAt = now();
        }

        return EventReminder::updateOrCreate(
            [
                'event_version_id' => $this->eventVersion->getId(),
                'notification_type' => EmailTemplateEnum::BOOKING_REMINDER->value,
            ],
            [
                'apps_id' => $this->eventVersion->app->getId(),
                'companies_id' => $this->eventVersion->company->getId(),
                'users_id' => $this->eventVersion->user->getId(),
                'send_at' => $sendAt,
                'status' => EventReminderStatusEnum::PENDING->value,
                'sent_at' => null,
                'failed_at' => null,
                'failure_reason' => null,
                'idempotency_key' => sha1($this->eventVersion->getId() . '|' . EmailTemplateEnum::BOOKING_REMINDER->value . '|' . $startAt->toIso8601String()),
                'metadata' => [
                    'start_at' => $startAt->toIso8601String(),
                    'offset_in_minutes' => $this->getOffsetInMinutes(),
                ],
            ]
        );
    }

    protected function getOffsetInMinutes(): int
    {
        return $this->offsetInMinutes
            ?? (int) ($this->eventVersion->app->get('booking_reminder_offset_minutes') ?? 1440);
    }

    protected function cancelPendingReminder(): void
    {
        EventReminder::query()
            ->where('event_version_id', $this->eventVersion->getId())
            ->where('notification_type', EmailTemplateEnum::BOOKING_REMINDER->value)
            ->where('status', EventReminderStatusEnum::PENDING->value)
            ->update([
                'status' => EventReminderStatusEnum::CANCELED->value,
                'failure_reason' => 'Reminder canceled because the booking is no longer eligible.',
            ]);
    }

    protected function isCancelled(): bool
    {
        $metadata = $this->eventVersion->metadata ?? [];

        return $this->eventVersion->event->eventStatus?->name === EventStatusEnum::CANCELLED->value
            || ($metadata['status'] ?? null) === EventStatusEnum::CANCELLED->value
            || isset($metadata['cancelled_at']);
    }
}
