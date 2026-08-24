<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Webhooks;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Override;

#[WorkflowAction(
    name: 'ElevenLabs Hand Off To Human',
    description: 'One of the endpoints an ElevenLabs VOICE agent calls back into Kanvas mid-call. These are '
        . 'wired as that agent\'s server-side tools, not chosen as workflow steps — the caller on the '
        . 'phone triggers them. This one hands the call off — the voice agent has decided it should '
        . 'stop and a person should take over, and passes on its summary of the conversation so far.',
)]
class ProcessElevenLabsHandOffWebhookJob extends ProcessElevenLabsWebhookJob
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

        $lead = $this->resolveLeadByPhone($phone);

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
}
