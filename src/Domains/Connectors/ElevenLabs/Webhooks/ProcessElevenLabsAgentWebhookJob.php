<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Webhooks;

use Baka\Support\Str;
use Kanvas\ActionEngine\Engagements\Traits\GeneratesChecklistEngagementUrls;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Intelligence\Tools\LeadIntentTool;
use Kanvas\Intelligence\Tools\VehicleInterestTool;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Override;

#[WorkflowAction(
    name: 'ElevenLabs Caller Lookup',
    description: 'One of the endpoints an ElevenLabs VOICE agent calls back into Kanvas mid-call. These are '
        . 'wired as that agent\'s server-side tools, not chosen as workflow steps — the caller on the '
        . 'phone triggers them. This one looks the caller up by phone number and answers with who they '
        . 'are and what they already have open — existing person, live lead, vehicle of interest, '
        . 'intent. It is how the voice agent knows whether it is talking to a stranger or a customer.',
)]
class ProcessElevenLabsAgentWebhookJob extends ProcessElevenLabsWebhookJob
{
    use GeneratesChecklistEngagementUrls;

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
        $currentDate = now($company->get('timezone') ?? $company->timezone ?? 'UTC')->toDateTimeString();

        $people = $this->findPeopleByPhone($normalizedPhone, $phone);

        if (! $people) {
            return [
                'contact_exists' => false,
                'has_open_opportunity' => false,
                'opportunity_type' => null,
                'opportunity_details' => null,
                'lead' => null,
                'phone' => $normalizedPhone,
                'current_date' => $currentDate,
                'voice_context' => [],
                'session' => [],
            ];
        }

        $lead = LeadsRepository::getPeopleActiveLead($people);

        if (! $lead) {
            return [
                'contact_exists' => true,
                'has_open_opportunity' => false,
                'opportunity_type' => null,
                'opportunity_details' => null,
                'lead' => null,
                'people' => [
                    'id' => $people->getId(),
                    'firstname' => (string) $people->firstname,
                    'lastname' => (string) $people->lastname,
                    'email' => $people->getEmails()->first()?->value,
                    'phone' => $normalizedPhone,
                ],
                'current_date' => $currentDate,
                'voice_context' => [],
                'session' => [],
            ];
        }

        $opportunityType = $this->resolveOpportunityType($lead);
        $opportunityDetails = $this->buildOpportunityDetails($lead, $opportunityType);
        $checkList = $this->generateChecklistEngagementUrls($lead);

        return [
            'contact_exists' => true,
            'has_open_opportunity' => true,
            'opportunity_type' => $opportunityType,
            'opportunity_details' => $opportunityDetails,
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
                'current_date' => $currentDate,
                'ai_mode' => $lead->get(ConfigurationEnum::AI_MODE->value),
                'owner' => $lead->owner ? trim((string) $lead->owner->firstname . ' ' . (string) $lead->owner->lastname) : null,
            ],
            'checklist' => $checkList,
            'voice_context' => [],
            'session' => [],
        ];
    }

    protected function resolveOpportunityType(Lead $lead): string
    {
        $leadTypeFromCustomField = $lead->get('lead_type');
        $leadTypeName = (string) ($leadTypeFromCustomField ?: ($lead->type?->name ?? ''));

        return match (strtolower($leadTypeName)) {
            'service', 'service appointment', 'part', 'parts order' => 'service',
            default => 'sales',
        };
    }

    protected function buildOpportunityDetails(Lead $lead, string $opportunityType): array
    {
        if ($opportunityType === 'service') {
            return [
                'vehicle_in_service' => [
                    'year' => $lead->get('vehicleYear') ?? $lead->get('year'),
                    'make' => $lead->get('vehicleMake') ?? $lead->get('vehicleBrand'),
                    'model' => $lead->get('vehicleModel') ?? $lead->get('model'),
                    'trim' => $lead->get('vehicleTrim'),
                    'vin' => $lead->get('vehicleVin'),
                    'mileage' => $lead->get('vehicleMileage') ?? $lead->get('mileage'),
                    'preferred_date' => $lead->get('preferred_date') ?? $lead->get('appointmentDate'),
                    'appointment_time' => $lead->get('appointmentTime'),
                ],
                'service_type' => $lead->get('service_type') ?? $lead->type?->name,
            ];
        }

        return [
            'vehicle_of_interest' => new VehicleInterestTool($lead)->execute(),
            'lead_intent' => new LeadIntentTool($lead)->execute(),
        ];
    }
}
