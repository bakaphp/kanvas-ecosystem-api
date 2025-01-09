<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Ghost\Jobs;

use Carbon\Carbon;
use Kanvas\Connectors\Ghost\Enums\CustomFieldEnum;
use Kanvas\Connectors\Ghost\Enums\CustomFieldEventWebhookEnum;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class CreateEventFromGhostReceiverJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        $company = $this->webhookRequest->receiverWebhook->company;
        $payload = $this->webhookRequest->payload['post']['current'];
        $app = $this->webhookRequest->receiverWebhook->app;
        $eventType = $this->getType($payload);
        if (! $eventType) {
            return [];
        }
        $category = EventCategory::where('companies_id', $company->getId())
        ->where('apps_id', $this->webhookRequest->receiverWebhook->app->getId())
        ->first();
        $date = new Carbon($payload['published_at']);
        $data = [
            'name' => $payload['title'],
            'slug' => $payload['slug'],
            'type_id' => $eventType->getId(),
            'category_id' => $category->getId(),
            'dates' => [
                [
                    'date' => $date->format('Y-m-d'),
                ],
            ],
        ];
        if ($payload['primary_tag']['name'] == CustomFieldEnum::GHOST_EVENT_WEB_FORUM->value) {
            $data['meeting_link'] = $payload['tags'][2]['name'];
        }
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
        $primaryTag = $payload['primary_tag'];
        $app = $this->webhookRequest->receiverWebhook->app;
        $eventType = match ($primaryTag['name']) {
            CustomFieldEnum::GHOST_EVENT_WEB_FORUM->value => $app->get(CustomFieldEventWebhookEnum::WEBHOOK_WEB_FORUM_EVENT->value),
            CustomFieldEnum::GHOST_EVENT_IS_REPORT->value => $app->get(CustomFieldEventWebhookEnum::WEBHOOK_IS_REPORT_EVENT->value),
            default => null,
        };
        if (! $eventType) {
            return null;
        }

        return EventType::where('apps_id', $this->webhookRequest->receiverWebhook->app->getId())
             ->where('companies_id', $this->webhookRequest->receiverWebhook->company->getId())
             ->where('name', $eventType)
             ->first();
    }
}
