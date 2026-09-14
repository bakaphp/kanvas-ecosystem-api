<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\DataTransferObject;

use Kanvas\Guild\Leads\Models\Lead;
use Override;
use Spatie\LaravelData\Data;

/**
 * toArray() is cross-repo contract surface: clients read these keys structurally, so never
 * drop one. It is overridden because the wire shape flattens the lead, not the model.
 */
class LeadCandidate extends Data
{
    public function __construct(
        public readonly Lead $lead,
        public readonly float $rank = 1.0,
    ) {
    }

    #[Override]
    public function toArray(): array
    {
        $lead = $this->lead;
        $people = $lead->people;

        return [
            'id' => $lead->id,
            'uuid' => $lead->uuid,
            'people_id' => $people->id,
            'firstname' => $people->firstname,
            'middlename' => $people->middlename,
            'lastname' => $people->lastname,
            'email' => $people->getEmails()->first()?->value,
            'phone' => $people->getAllPhones()->first()?->value,
            // Clients compare against lowercase literals and type status as a non-nullable string.
            'status' => strtolower($lead->status()->first()?->name ?? ''),
            'lead_type' => $lead->type?->name,
            'owner' => $lead->owner?->name,
            'owner_id' => $lead->leads_owner_id,
            'custom_fields' => $lead->getAllCustomFields(),
            'rank' => $this->rank,
            'recentlyCreated' => $lead->wasRecentlyCreated,
            'updated_at' => $lead->updated_at,
        ];
    }
}
