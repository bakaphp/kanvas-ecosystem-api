<?php

declare(strict_types=1);

namespace Kanvas\Guild\Deals\DataTransferObject;

use Kanvas\Guild\Deals\Models\Deal;
use Override;
use Spatie\LaravelData\Data;

/**
 * The deal counterpart of LeadCandidate: toArray() is what the pull button reads, so its keys are
 * contract surface — never drop one. Overridden because Spatie would serialize the whole model.
 */
class DealCandidate extends Data
{
    public function __construct(
        public readonly Deal $deal,
        public readonly float $rank = 1.0,
    ) {
    }

    #[Override]
    public function toArray(): array
    {
        $deal = $this->deal;
        $people = $deal->people;

        return [
            'id' => $deal->id,
            'uuid' => $deal->uuid,
            'title' => $deal->title,
            'description' => $deal->description,
            'people_id' => $deal->people_id,
            'firstname' => $people?->firstname,
            'middlename' => $people?->middlename,
            'lastname' => $people?->lastname,
            'email' => $people?->getEmails()->first()?->value,
            'phone' => $people?->getAllPhones()->first()?->value,
            // Clients compare against lowercase literals and type status as a non-nullable string.
            'status' => strtolower($deal->statusName() ?? ''),
            'stage' => $deal->pipelineStage?->name,
            'lead_id' => $deal->leads_id,
            'owner' => $deal->owner?->name,
            'owner_id' => $deal->owner_id,
            'custom_fields' => $deal->getAllCustomFields(),
            'rank' => $this->rank,
            'recentlyCreated' => $deal->wasRecentlyCreated,
            'updated_at' => $deal->updated_at,
        ];
    }
}
