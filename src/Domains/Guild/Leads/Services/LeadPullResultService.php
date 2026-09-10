<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Services;

use Kanvas\Guild\Leads\Models\Lead;

/**
 * The wire shape every CRM connector returns from a lead pull or search.
 *
 * It exists because this array was hand-written in seven places across the
 * connectors and drifted on three axes — status casing, nullability, and which
 * accessor fed owner/phone — so the same lead came back differently depending on
 * which dealer's CRM produced it. Clients live in a separate repo and consume it
 * structurally, so every key here is contract surface: add one only when a client
 * needs it, and never drop one.
 */
class LeadPullResultService
{
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
    public static function toArray(Lead $lead, float $rank = 1.0): array
    {
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
            'rank' => $rank,
            'recentlyCreated' => $lead->wasRecentlyCreated,
            'updated_at' => $lead->updated_at,
        ];
    }
}
