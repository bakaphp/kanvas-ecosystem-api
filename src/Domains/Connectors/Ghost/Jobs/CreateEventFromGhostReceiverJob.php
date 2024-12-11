<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Ghost\Jobs;

use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\DataTransferObject\Event;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Connectors\Ghost\Enums\CustomFieldEnum;
use Illuminate\Support\Str;

class CreateEventFromGhostReceiverJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        $company = $this->webhookRequest->receiverWebhook->company;
        $payload = $this->webhookRequest->payload['posts'][0];
        $eventType = $this->getType($payload);
        if (! $eventType) {
            return [];
        }
        $category = EventCategory::where('companies_id', $company->getId())
                    ->first();

        $data = [
            'name' => $payload['primary_tag']['name'],
            'slug' => Str::slug($payload['primary_tag']['name']),
            'type_id' => $eventType->getId(),
            'category_id' => $category->getId(),
        ];
        $dto = Event::fromMultiple(
            $this->webhookRequest->receiverWebhook->app,
            $this->webhookRequest->receiverWebhook->user,
            $company,
            $data,
        );
        $event = (new CreateEventAction($dto))->execute();
        return $event->toArray();
    }

    public function getType(array $payload): ?EventType
    {
        $eventTypeQuery = EventType::where('apps_id', $this->webhookRequest->receiverWebhook->app->getId())
            ->where('companies_id', $this->webhookRequest->receiverWebhook->company->getId());
        if (isset($payload['primary_tag']['is_report'])  && $payload['primary_tag']['is_report']) {
            $eventTypeName = $this->webhookRequest->receiverWebhook->app->get(CustomFieldEnum::WEBHOOK_IS_REPORT_EVENT->value);
            return $eventTypeQuery->where('name', $eventTypeName)
                ->first();
        }
        return null;
    }
}
