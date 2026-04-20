<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Webhooks;

use Baka\Support\Str;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Override;

class ProcessElevenLabsAgentWebhookJob extends ProcessElevenLabsWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = (array) $this->webhookRequest->payload;
        $phone = isset($payload['phone']) ? (string) $payload['phone'] : null;

        if ($phone === null || $phone === '') {
            $this->failedReturnHttpCode = 422;

            return ['status' => 422, 'message' => 'Phone number is required'];
        }

        $company = $this->receiver->company;
        $normalizedPhone = Str::normalizePhoneNumber($phone);

        $people = $this->findPeopleByPhone($normalizedPhone, $phone);

        if (! $people) {
            $this->failedReturnHttpCode = 404;

            return ['status' => 404, 'message' => 'No customer found for phone: ' . $normalizedPhone];
        }

        $lead = LeadsRepository::getPeopleActiveLead($people);

        if (! $lead) {
            $this->failedReturnHttpCode = 404;

            return ['status' => 404, 'message' => 'No active lead found for phone: ' . $normalizedPhone];
        }

        return [
            'lead' => [
                'id' => $lead->getId(),
                'uuid' => $lead->uuid,
                'company' => $lead->company->name,
                'firstname' => (string) $lead->people->firstname,
                'lastname' => (string) $lead->people->lastname,
                'email' => $lead->people->getEmails()->first()?->value,
                'phone' => $normalizedPhone,
                'title' => $lead->title,
                'pipeline' => $lead->pipeline?->name,
                'stage' => $lead->stage?->name,
                'current_date' => now($company->get('timezone') ?? $company->timezone ?? 'UTC')->toDateTimeString(),
                'ai_mode' => $lead->get(ConfigurationEnum::AI_MODE->value),
                'owner' => $lead->owner ? trim((string) $lead->owner->firstname . ' ' . (string) $lead->owner->lastname) : null,
            ],
            'voice_context' => [],
            'session' => [],
        ];
    }
}
