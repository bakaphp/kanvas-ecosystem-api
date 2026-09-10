<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Services;

use Kanvas\Guild\Leads\Models\Lead;

/**
 * One candidate from a CRM lead pull or search: the lead, plus how well it
 * matched what the caller asked for.
 *
 * It exists because the wire array below was hand-written in eight places across
 * the connectors and drifted on three axes — status casing, nullability, and
 * which accessor fed owner/phone — so the same lead came back differently
 * depending on which dealer's CRM produced it.
 *
 * It holds the Lead rather than copying its fields, so there is one source of
 * truth and no 16-argument constructor. toArray() is the boundary: clients live
 * in a separate repo and read the result structurally, so every key it emits is
 * contract surface. Add one only when a client needs it, and never drop one.
 */
final class LeadPullResult
{
    private function __construct(
        public readonly Lead $lead,
        public readonly float $rank,
    ) {
    }

    public static function for(Lead $lead, float $rank = 1.0): self
    {
        return new self($lead, $rank);
    }

    /**
     * @return array{
     *     id: int,
     *     uuid: string,
     *     people_id: int,
     *     firstname: string|null,
     *     middlename: string|null,
     *     lastname: string|null,
     *     email: string|null,
     *     phone: string|null,
     *     status: string,
     *     lead_type: string|null,
     *     owner: string|null,
     *     owner_id: int|null,
     *     custom_fields: array<string, mixed>,
     *     rank: float,
     *     recentlyCreated: bool,
     *     updated_at: mixed
     * }
     */
    public function toArray(): array
    {
        $lead = $this->lead;

        return [
            'id' => $lead->id,
            'uuid' => $lead->uuid,
            'people_id' => $lead->people->id,
            'firstname' => $lead->people->firstname,
            'middlename' => $lead->people->middlename,
            'lastname' => $lead->people->lastname,
            'email' => $lead->people?->getEmails()->first()?->value,
            'phone' => $lead->people?->getAllPhones()->first()?->value,
            // Lowercase and never null: clients compare against lowercase literals
            // and their type declares a non-nullable string.
            'status' => strtolower((string) ($lead->status()?->first()?->name ?? '')),
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
