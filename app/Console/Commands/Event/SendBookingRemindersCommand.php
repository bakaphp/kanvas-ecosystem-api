<?php

declare(strict_types=1);

namespace App\Console\Commands\Event;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;
use Kanvas\Event\Events\Actions\SendEventEmailsAction;
use Kanvas\Event\Events\Enums\EmailTemplateEnum;
use Kanvas\Event\Events\Enums\EventReminderStatusEnum;
use Kanvas\Event\Events\Enums\EventStatusEnum;
use Kanvas\Event\Events\Models\EventReminder;

class SendBookingRemindersCommand extends Command
{
    protected $signature = 'kanvas:events:send-booking-reminders {--limit=100}';

    protected $description = 'Send due booking reminder emails for upcoming appointments';

    public function handle(): int
    {
        $processed = 0;
        $limit = (int) $this->option('limit');

        EventReminder::query()
            ->where('notification_type', EmailTemplateEnum::BOOKING_REMINDER->value)
            ->where('status', EventReminderStatusEnum::PENDING->value)
            ->where('send_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (EventReminder $reminder) use (&$processed) {
                $reminder->load('eventVersion.event.eventStatus', 'eventVersion.dates', 'eventVersion.participants.people');

                if ($this->shouldCancelReminder($reminder)) {
                    $reminder->update([
                        'status' => EventReminderStatusEnum::CANCELED->value,
                        'failure_reason' => 'Reminder canceled because the booking is canceled or already started.',
                    ]);

                    return;
                }

                try {
                    new SendEventEmailsAction(
                        $reminder->eventVersion,
                        EmailTemplateEnum::BOOKING_REMINDER->value,
                        [
                            'reminder_send_at' => $reminder->send_at?->toIso8601String(),
                        ]
                    )->execute();

                    $reminder->update([
                        'status' => EventReminderStatusEnum::SENT->value,
                        'sent_at' => now(),
                        'failed_at' => null,
                        'failure_reason' => null,
                    ]);

                    $processed++;
                } catch (Throwable $exception) {
                    $reminder->update([
                        'status' => EventReminderStatusEnum::FAILED->value,
                        'failed_at' => now(),
                        'failure_reason' => $exception->getMessage(),
                    ]);
                }
            });

        $this->info("Processed {$processed} booking reminders.");

        return self::SUCCESS;
    }

    protected function shouldCancelReminder(EventReminder $reminder): bool
    {
        $metadata = $reminder->eventVersion->metadata ?? [];
        $firstDate = $reminder->eventVersion->dates->sortBy('event_date')->first();

        if ($firstDate === null) {
            return true;
        }

        $startAt = Carbon::parse($firstDate->event_date->format('Y-m-d') . ' ' . $firstDate->start_time);

        return $reminder->eventVersion->event->eventStatus?->name === EventStatusEnum::CANCELLED->value
            || ($metadata['status'] ?? null) === EventStatusEnum::CANCELLED->value
            || isset($metadata['cancelled_at'])
            || $startAt->lessThanOrEqualTo(now());
    }
}
