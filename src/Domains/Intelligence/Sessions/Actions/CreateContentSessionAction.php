<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Actions;

use Baka\Support\Str;
use Exception;
use Illuminate\Support\Facades\Blade;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;

class CreateContentSessionAction
{
    public function __construct(
        public string $entityNamespace,
        public int|string $entityId,
        public ?Agent $agent = null,
        public ?CompaniesBranches $branch = null,
    ) {
    }

    public function execute(): array
    {
        return match ($this->entityNamespace) {
            People::class => $this->mapPeople(People::getById($this->entityId)),
            Lead::class => $this->mapLead(Lead::getById($this->entityId)),
            default => [],
        };
    }

    protected function mapLead(Lead $lead): array
    {
        return array_merge(
            [
                'lead_id' => $lead->id,
                'lead_channel_id' => $lead->uuid,
                'type' => $lead->type?->name,
                'status' => $lead->status()->first()?->name,
            ],
            $this->mapPeople($lead->people, $lead)
        );
    }

    protected function mapPeople(People $people, ?Lead $lead = null): array
    {
        $data = [
            'creditApp' => 'https://kanvas.dev/credit-app',
            'tradeIn' => 'https://kanvas.dev/trade-in',
        ];

        if ($lead) {
            $data['leadOwnerEmail'] = $lead->owner?->email;
            $data['customerName'] = $people->name;
            $data['leadEmail'] = $people->getEmails()->first()?->value ?? '';
            $data['leadOwnerName'] = $lead->owner?->firstname . ' ' . $lead->owner?->lastname;
        }

        try {
            $background = $this->agent?->role !== null && is_array($this->agent->role) ? Blade::render(json_encode($this->agent->role), $data) : null;
        } catch (Exception $e) {
            $background = $this->agent?->role;
        }

        return [
            'branch' => $this->branch,
            'people_id' => $people->id,
            'firstname' => $people->firstname,
            'lastname' => $people->lastname,
            'middlename' => $people->middlename,
            'leads' => $people->leads->toArray(),
            'address' => $people->address->toArray(),
            'contacts' => $people->contacts->toArray(),
            'background' => Str::isJson($background) ? json_decode($background) : $background,
            'checklist' => [
                'creditApp' => 'https://kanvas.dev/credit-app',
                'tradeIn' => 'https://kanvas.dev/trade-in',
            ],
        ];
    }
}
