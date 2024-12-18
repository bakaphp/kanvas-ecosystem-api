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
use Kanvas\Connectors\Ghost\Enums\CustomFieldEventWebhookEnum;

class CreateEventFromGhostReceiverJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        $company = $this->webhookRequest->receiverWebhook->company;
        $payload = $this->webhookRequest->payload['post']['current'];
        $eventType = $this->getType($payload);
        if (! $eventType) {
            return [];
        }
        $category = EventCategory::where('companies_id', $company->getId())
                    ->where('apps_id', $this->webhookRequest->receiverWebhook->app->getId())
                    ->first();
        $data = [
            'name' => $payload['title'],
            'slug' => $payload['slug'],
            'type_id' => $eventType->getId(),
            'category_id' => $category->getId(),
            'dates' => [
                [
                    'date' => $payload['published_at'],
                ]
            ]
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
        $primaryTag = $payload['primary_tag'];
        $app = $this->webhookRequest->receiverWebhook->app;
        if ($primaryTag['name'] == CustomFieldEnum::GHOST_EVENT_WEB_FORUM->value) {
            $eventType = $app->get(CustomFieldEventWebhookEnum::WEBHOOK_WEB_FORUM_EVENT->value);
        } elseif (empty($primaryTag['is_report'])) {
            $eventType = $app->get(CustomFieldEventWebhookEnum::WEBHOOK_IS_REPORT_EVENT->value);
            if (!$eventType) {
                return null;
            }
        }
        return EventType::where('apps_id', $this->webhookRequest->receiverWebhook->app->getId())
             ->where('companies_id', $this->webhookRequest->receiverWebhook->company->getId())
             ->where('name', $eventType)
             ->first();
    }
}
