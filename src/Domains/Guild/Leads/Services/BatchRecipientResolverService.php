<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Services;

use Illuminate\Support\Collection;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Enums\ContactValidationStatusEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * Turns a candidate set of leads into a vetted batch-outreach recipient list: dedups people who
 * appear on more than one lead, drops leads flagged do_not_contact, and drops leads with no
 * deliverable contact for the chosen channel (opted-out / bounced / missing). Every drop carries a
 * reason so the caller can tell the manager WHY a lead isn't in the list.
 *
 * This is the single compliance chokepoint for the batch flow — the read-only trait finder uses it
 * to show the list, and the send action re-runs it server-side so it never trusts a recipient set
 * the LLM (or a stale UI) hands back.
 */
class BatchRecipientResolverService
{
    private const array EMAIL_TYPES = [
        ContactTypeEnum::EMAIL->value,
        ContactTypeEnum::PRIMARY_EMAIL->value,
        ContactTypeEnum::SECONDARY_EMAIL->value,
    ];

    /**
     * @param  Collection<int, Lead>  $leads  Candidate leads, ordered most-relevant first (dedup keeps the first per person).
     * @param  string  $channel  'sms' or 'email'
     * @return array{
     *     eligible: array<int, array<string, mixed>>,
     *     excluded: array<int, array<string, mixed>>,
     *     total_candidates: int,
     *     eligible_count: int,
     *     excluded_count: int
     * }
     */
    public function resolve(Collection $leads, string $channel): array
    {
        $typeIds = $channel === 'email' ? self::EMAIL_TYPES : Contact::PHONE_TYPES;

        $peopleIds = $leads->pluck('people_id')->filter()->unique()->values()->all();
        [$hasAnyContact, $hasDeliverableContact] = $this->contactAvailability($peopleIds, $typeIds);

        $eligible = [];
        $excluded = [];
        $seenPeople = [];

        foreach ($leads as $lead) {
            $peopleId = (int) $lead->people_id;

            if ($peopleId !== 0 && isset($seenPeople[$peopleId])) {
                $excluded[] = $this->row($lead, 'duplicate');

                continue;
            }
            $seenPeople[$peopleId] = true;

            if ((bool) $lead->get('do_not_contact')) {
                $excluded[] = $this->row($lead, 'do_not_contact');

                continue;
            }

            if (! ($hasDeliverableContact[$peopleId] ?? false)) {
                $reason = ($hasAnyContact[$peopleId] ?? false) ? 'opted_out_or_undeliverable' : 'no_contact_info';
                $excluded[] = $this->row($lead, $reason);

                continue;
            }

            $eligible[] = $this->row($lead, 'eligible');
        }

        return [
            'eligible' => $eligible,
            'excluded' => $excluded,
            'total_candidates' => $leads->count(),
            'eligible_count' => count($eligible),
            'excluded_count' => count($excluded),
        ];
    }

    /**
     * @param  Collection<int, Lead>  $leads
     * @param  string  $channel  'sms' or 'email'
     * @return array<int, int>  Lead ids that are eligible to receive the batch on this channel.
     */
    public function eligibleLeadIds(Collection $leads, string $channel): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['lead_id'],
            $this->resolve($leads, $channel)['eligible'],
        );
    }

    /**
     * The single deliverable destination the send should use for a lead on this channel — same
     * eligibility rule as resolve() (opted-in, not permanently failed, NULL status is deliverable),
     * highest weight first. The send action passes this explicitly instead of falling back to the
     * lead's first contact, so a person's opted-out primary number can't leak a send.
     *
     * @param  string  $channel  'sms' or 'email'
     */
    public function deliverableContactValue(Lead $lead, string $channel): ?string
    {
        $typeIds = $channel === 'email' ? self::EMAIL_TYPES : Contact::PHONE_TYPES;

        $value = Contact::query()
            ->where('peoples_id', (int) $lead->people_id)
            ->whereIn('contacts_types_id', $typeIds)
            ->where('is_deleted', 0)
            ->where('is_opt_out', 0)
            ->where(fn ($q) => $q->whereNull('validation_status')->orWhereNotIn('validation_status', [
                ContactValidationStatusEnum::HARD_BOUNCE->value,
                ContactValidationStatusEnum::INVALID->value,
            ]))
            ->orderByDesc('weight')
            ->value('value');

        return $value !== null ? (string) $value : null;
    }

    /**
     * One grouped query for every candidate person, split into "has any contact of this channel type"
     * (drives the reason) and "has a deliverable one" (drives eligibility).
     *
     * @param  array<int, int>  $peopleIds
     * @param  array<int, int>  $typeIds
     * @return array{0: array<int, bool>, 1: array<int, bool>}
     */
    private function contactAvailability(array $peopleIds, array $typeIds): array
    {
        $hasAny = [];
        $hasDeliverable = [];

        if ($peopleIds === []) {
            return [$hasAny, $hasDeliverable];
        }

        Contact::query()
            ->whereIn('peoples_id', $peopleIds)
            ->whereIn('contacts_types_id', $typeIds)
            ->where('is_deleted', 0)
            ->get(['peoples_id', 'is_opt_out', 'validation_status'])
            ->each(function (Contact $contact) use (&$hasAny, &$hasDeliverable): void {
                $peopleId = (int) $contact->peoples_id;
                $hasAny[$peopleId] = true;

                // Mirror Contact::scopeDeliverable in PHP against the raw stored value (a null
                // validation_status is deliverable), so a factory-default contact with no status
                // still counts and the enum cast can't trip on null.
                $permanentFailure = in_array(
                    $contact->getRawOriginal('validation_status'),
                    [ContactValidationStatusEnum::HARD_BOUNCE->value, ContactValidationStatusEnum::INVALID->value],
                    true,
                );
                if ($contact->is_opt_out === 0 && ! $permanentFailure) {
                    $hasDeliverable[$peopleId] = true;
                }
            });

        return [$hasAny, $hasDeliverable];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Lead $lead, string $status): array
    {
        return [
            'lead_id' => $lead->getId(),
            'people_id' => (int) $lead->people_id,
            'name' => $lead->people?->getName() ?? $lead->title,
            'salesperson' => $lead->owner ? trim($lead->owner->firstname . ' ' . $lead->owner->lastname) : null,
            'stage' => $lead->stage?->name,
            'last_activity_at' => $lead->updated_at?->toDateString(),
            'compliance_status' => $status,
        ];
    }
}
