<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Webhooks;

use Baka\Support\Str;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Override;

class ProcessElevenLabsConversationInitiationWebhookJob extends ProcessElevenLabsWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = (array) $this->webhookRequest->payload;

        $phone = $this->extractCallerPhone($payload);
        $normalizedPhone = $phone !== '' ? Str::normalizePhoneNumber($phone) : '';

        $company = $this->receiver->company;
        $timezone = $company->get('timezone') ?? $company->timezone ?? 'UTC';
        $currentDate = now($timezone)->toDateTimeString();

        $dynamicVariables = [
            'phone' => $normalizedPhone,
            'caller_id' => $normalizedPhone,
            'called_number' => isset($payload['called_number']) ? (string) $payload['called_number'] : '',
            'call_sid' => isset($payload['call_sid']) ? (string) $payload['call_sid'] : '',
            'elevenlabs_agent_id' => isset($payload['agent_id']) ? (string) $payload['agent_id'] : '',
            'current_date' => $currentDate,
            'timezone' => (string) $timezone,
            'company_name' => $company->name,
            'company_language' => $company->language,
            'contact_exists' => false,
            'has_open_opportunity' => false,
            'customer_name' => '',
            'firstname' => '',
            'lastname' => '',
            'email' => '',
            'lead_id' => '',
            'lead_uuid' => '',
            'lead_title' => '',
            'lead_stage' => '',
            'lead_pipeline' => '',
            'opportunity_type' => '',
            'owner_name' => '',
            'ai_mode' => '',
            'vehicle_year' => '',
            'vehicle_make' => '',
            'vehicle_model' => '',
            'vehicle_trim' => '',
            'vehicle_vin' => '',
            'vehicle_mileage' => '',
            'preferred_date' => '',
            'appointment_time' => '',
        ];

        if ($normalizedPhone === '') {
            return $this->conversationInitiationResponse($dynamicVariables);
        }

        $people = $this->findPeopleByPhone($normalizedPhone, $phone);

        if ($people === null) {
            return $this->conversationInitiationResponse($dynamicVariables);
        }

        $dynamicVariables['contact_exists'] = true;
        $dynamicVariables['firstname'] = (string) $people->firstname;
        $dynamicVariables['lastname'] = (string) $people->lastname;
        $dynamicVariables['customer_name'] = trim((string) $people->firstname . ' ' . (string) $people->lastname);
        $dynamicVariables['email'] = (string) ($people->getEmails()->first()?->value ?? '');

        $lead = LeadsRepository::getPeopleActiveLead($people);

        if ($lead === null) {
            return $this->conversationInitiationResponse($dynamicVariables);
        }

        return $this->conversationInitiationResponse(
            array_merge($dynamicVariables, $this->leadDynamicVariables($lead))
        );
    }

    protected function extractCallerPhone(array $payload): string
    {
        foreach (['caller_id', 'phone', 'from_number', 'from'] as $field) {
            if (isset($payload[$field]) && (string) $payload[$field] !== '') {
                return (string) $payload[$field];
            }
        }

        return '';
    }

    protected function conversationInitiationResponse(array $dynamicVariables): array
    {
        return [
            'type' => 'conversation_initiation_client_data',
            'dynamic_variables' => $dynamicVariables,
        ];
    }

    protected function leadDynamicVariables(Lead $lead): array
    {
        return [
            'has_open_opportunity' => true,
            'lead_id' => (string) $lead->getId(),
            'lead_uuid' => (string) $lead->uuid,
            'lead_title' => (string) $lead->title,
            'lead_stage' => (string) ($lead->stage?->name ?? ''),
            'lead_pipeline' => (string) ($lead->pipeline?->name ?? ''),
            'opportunity_type' => $this->resolveOpportunityType($lead),
            'owner_name' => $lead->owner ? trim((string) $lead->owner->firstname . ' ' . (string) $lead->owner->lastname) : '',
            'ai_mode' => (string) ($lead->get(ConfigurationEnum::AI_MODE->value) ?? ''),
            'vehicle_year' => (string) ($lead->get('vehicleYear') ?? $lead->get('year') ?? ''),
            'vehicle_make' => (string) ($lead->get('vehicleMake') ?? $lead->get('vehicleBrand') ?? ''),
            'vehicle_model' => (string) ($lead->get('vehicleModel') ?? $lead->get('model') ?? ''),
            'vehicle_trim' => (string) ($lead->get('vehicleTrim') ?? ''),
            'vehicle_vin' => (string) ($lead->get('vehicleVin') ?? ''),
            'vehicle_mileage' => (string) ($lead->get('vehicleMileage') ?? $lead->get('mileage') ?? ''),
            'preferred_date' => (string) ($lead->get('preferred_date') ?? $lead->get('appointmentDate') ?? ''),
            'appointment_time' => (string) ($lead->get('appointmentTime') ?? ''),
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
}
