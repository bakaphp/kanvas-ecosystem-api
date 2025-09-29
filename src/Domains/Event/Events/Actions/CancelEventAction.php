<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Kanvas\Event\Events\Enums\EmailTemplateEnum;
use Kanvas\Event\Events\Enums\EventStatusEnum;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Exceptions\ValidationException;

class CancelEventAction
{
    public function __construct(
        private EventVersion $eventVersion
    ) {
    }

    public function execute(): EventVersion
    {
        // Check if event is already cancelled
        $this->validateEventNotAlreadyCancelled();

        $cancelledStatus = EventStatus::firstOrCreate([
            'companies_id' => $this->eventVersion->companies_id,
            'apps_id' => $this->eventVersion->apps_id,
            'name' => EventStatusEnum::CANCELLED->value,
        ], [
            'users_id' => $this->eventVersion->users_id,
        ]);

        $currentMetadata = $this->eventVersion->metadata ?? [];
        $cancelledAt = now()->toDateTimeString();

        $this->eventVersion->event->update(['event_status_id' => $cancelledStatus->id]);
        $this->eventVersion->update(['metadata' => [
            ...$currentMetadata,
            "cancelled_at" => $cancelledAt,
            "status" => EventStatusEnum::CANCELLED->value
        ]]);

        $this->eventVersion->refresh();
        new SendEventEmailsAction($this->eventVersion, EmailTemplateEnum::BOOKING_CANCELLED->value)->execute();

        return $this->eventVersion;
    }

    private function validateEventNotAlreadyCancelled(): void
    {
        // Check if event status is already "Cancelled"
        if ($this->eventVersion->event->eventStatus?->name === EventStatusEnum::CANCELLED->value) {
            throw new ValidationException('Event is already cancelled and cannot be cancelled again.');
        }

        // Also check event version metadata for cancelled status
        $metadata = $this->eventVersion->metadata ?? [];
        if (isset($metadata['status']) && $metadata['status'] === EventStatusEnum::CANCELLED->value) {
            throw new ValidationException('Event is already cancelled and cannot be cancelled again.');
        }

        // Check if cancelled_at timestamp exists in metadata
        if (isset($metadata['cancelled_at'])) {
            throw new ValidationException('Event is already cancelled and cannot be cancelled again.');
        }
    }
}
