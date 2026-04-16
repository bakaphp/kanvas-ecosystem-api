<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Webhooks;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class ProcessElevenLabsHandOffWebhookJob extends ProcessWebhookJob
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

        $app = $this->receiver->app;
        $normalizedPhone = Str::normalizePhoneNumber($phone);

        $lead = $this->findLeadByPhone($normalizedPhone, $phone);

        if (! $lead) {
            $this->failedReturnHttpCode = 404;

            return ['status' => 404, 'message' => 'No lead found for phone: ' . $normalizedPhone];
        }

        $this->updateLeadPeopleInfo($lead, $payload);

        $handoffType = isset($payload['handoff_type']) && (string) $payload['handoff_type'] !== ''
            ? strtolower((string) $payload['handoff_type'])
            : 'human';

        $conversationSummary = isset($payload['conversation_summary']) && (string) $payload['conversation_summary'] !== ''
            ? (string) $payload['conversation_summary']
            : '';

        $workflowParams = [
            'app' => $app,
            'handoff_type' => $handoffType,
            'source' => 'elevenlabs',
        ];

        if ($conversationSummary !== '') {
            $workflowParams['conversation_summary'] = $conversationSummary;
        }

        if (isset($payload['rotation_id']) && (string) $payload['rotation_id'] !== '') {
            $workflowParams['rotation_id'] = (string) $payload['rotation_id'];
        }

        $lead->fireWorkflow(
            WorkflowEnum::HANDOFF->value,
            true,
            $workflowParams,
        );

        return [
            'message' => 'Handoff triggered',
            'lead_id' => $lead->getId(),
            'lead_uuid' => $lead->uuid,
            'handoff_type' => $handoffType,
        ];
    }

    protected function updateLeadPeopleInfo(Lead $lead, array $payload): void
    {
        /** @var \Kanvas\Guild\Customers\Models\People $people */
        $people = $lead->people;
        $updated = false;

        $firstname = isset($payload['firstname']) ? (string) $payload['firstname'] : null;
        $lastname = isset($payload['lastname']) ? (string) $payload['lastname'] : null;

        if ($firstname !== null && $firstname !== '') {
            $people->firstname = $firstname;
            $updated = true;
        }

        if ($lastname !== null && $lastname !== '') {
            $people->lastname = $lastname;
            $updated = true;
        }

        if ($updated) {
            $people->saveOrFail();
        }
    }

    protected function findLeadByPhone(string $normalizedPhone, string $rawPhone): ?Lead
    {
        $digitsOnly = Str::sanitizePhoneNumber($rawPhone);

        $query = PeoplesRepository::getByPhoneNumber(
            app: $this->receiver->app,
            company: $this->receiver->company,
            phoneNumbers: array_unique([$digitsOnly, $normalizedPhone]),
        );

        $allCustomers = $query->get();

        $people = $allCustomers->first(function (People $customer): bool {
            return LeadsRepository::getPeopleActiveLead($customer) !== null;
        }) ?? $allCustomers->first();

        if (! $people) {
            return null;
        }

        return LeadsRepository::getPeopleActiveLead($people);
    }
}
