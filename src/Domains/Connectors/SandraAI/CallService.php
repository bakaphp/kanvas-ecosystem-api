<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SandraAI;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\Http;
use Kanvas\Guild\Leads\Models\Lead;

class CallService
{
    protected string $apiEndpoint;
    protected string $useCase;

    public function __construct(AppInterface $app)
    {
        $this->apiEndpoint = $app->get('sandraai_endpoint');
        $this->useCase = $app->get('sandraai_use_case');
    }

    public function sendLead(Lead $lead): array
    {
        if ($lead->get('sandraai_response')) {
            return $lead->get('sandraai_response');
        }

        $payload = $this->buildPayload($lead);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($this->apiEndpoint, $payload);

        $response = [
            'status' => $response->successful(),
            'status_code' => $response->status(),
            'body' => $response->json() ?: $response->body(),
        ];

        $lead->set('sandraai_response', $response);

        return $response;
    }

    public function buildPayload(Lead $lead): array
    {
        return [
            'use_case' => $this->useCase,
            'lead' => [
                'id' => (string) $lead->id,
                'uuid' => $lead->uuid,
                'title' => $lead->title,
                'firstname' => $lead->firstname,
                'lastname' => $lead->lastname,
                'company' => [
                    'id' => (string) $lead->companies_id,
                ],
                'people' => [
                    'id' => (string) $lead->people_id,
                    'name' => $lead->people->name ?? "{$lead->firstname} {$lead->lastname}",
                    'contacts' => $this->formatContacts($lead->people),
                ],
                'receiver' => [
                    'name' => $lead->receiver->name ?? 'Default',
                    'uuid' => $lead->receiver->uuid ?? null,
                ],
                'status' => [
                    'name' => $lead->status->name ?? 'Active',
                ],
                'type' => [
                    'name' => $lead->type->name ?? 'INTERNET',
                ],
                'source' => [
                    'name' => $lead->source->name ?? null,
                ],
                'pipeline' => [
                    'name' => $lead->pipeline->name ?? 'Default Leads',
                ],
                'stage' => [
                    'name' => $lead->stage->name ?? 'New',
                ],
                'owner' => $this->formatOwner($lead->owner),
                'custom_fields' => [
                    'data' => $this->getCustomFields($lead),
                ],
            ],
        ];
    }

    protected function getCustomFields(Lead $lead): array
    {
        $customFields = [];
        $fields = $lead->getAll();

        foreach ($fields as $key => $value) {
            $customFields[] = [
            'name' => $key,
            'value' => $value,
            ];
        }

        return $customFields;
    }

    protected function formatContacts($people): array
    {
        // Get unique contacts by type
        return $people->contacts()->get()
        ->unique(function ($contact) {
            return $contact->type->name ?? 'Unknown';
        })
        ->map(function ($contact) {
            return [
                'type' => [
                    'name' => $contact->type->name ?? 'Unknown',
                ],
                'value' => $contact->value,
            ];
        })
        ->values()
        ->toArray();
    }

    protected function formatOwner($owner): array
    {
        if (! $owner) {
            return [
                'id' => '',
                'firstname' => '',
                'lastname' => '',
                'email' => '',
                'contact' => [
                    'phone_number' => '',
                    'cell_phone_number' => '',
                ],
            ];
        }

        return [
            'id' => (string) $owner->id,
            'firstname' => $owner->firstname,
            'lastname' => $owner->lastname,
            'email' => $owner->email,
            'contact' => [
                'phone_number' => $owner->phone ?? '',
                'cell_phone_number' => $owner->cell_phone ?? '',
            ],
        ];
    }
}
