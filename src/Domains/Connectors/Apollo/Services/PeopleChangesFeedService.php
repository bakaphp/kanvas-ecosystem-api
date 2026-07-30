<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Enums\ContactValidationStatusEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\NervousSystem\Ledger\Models\Event;

/**
 * The change feed behind the Command Center "cambios" tools (list_changes / export_changes).
 *
 * Reads `people.enriched` ledger events and expands them into one row per changed field
 * (company / title / email / promotion) with before → after values, joining the live People
 * table for the person's name and current email deliverability. Same source and mapping the
 * dashboard uses; the list tool slices it, the export tool writes all of it to CSV.
 */
class PeopleChangesFeedService
{
    private const string EVENT_TYPE = 'people.enriched';
    private const string SOURCE_DOMAIN = 'Guild';

    /**
     * Ledger `changes.<field>` key → the change-type token the tools expose.
     * Only genuine before → after transitions live here; flags (new_account, location_added)
     * and contacts_added are intentionally excluded — they are not "before → after" rows.
     */
    private const array FIELD_TYPE = [
        'current_employer' => 'company',
        'title' => 'title',
        'email_changed' => 'email',
        'seniority_promoted' => 'promotion',
    ];

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
    ) {
    }

    /**
     * @param list<string>|null $changeTypes filter to these tokens (company/title/email/promotion); null = all
     *
     * @return list<array{crm: string, person: string, company: string, type: string, from: string, to: string, occurred_at: string, deliverability: string}>
     */
    public function rows(
        ?array $changeTypes = null,
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?int $limit = null,
    ): array {
        $wanted = $this->normalizeChangeTypes($changeTypes);

        $events = Event::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('event_type', self::EVENT_TYPE)
            ->where('source_domain', self::SOURCE_DOMAIN)
            ->where('material_change_count', '>', 0)
            ->when($from, fn (Builder $query): Builder => $query->where('occurred_at', '>=', $from))
            ->when($to, fn (Builder $query): Builder => $query->where('occurred_at', '<=', $to))
            ->orderByDesc('occurred_at')
            ->get(['source_entity_id', 'occurred_at', 'payload']);

        $peopleById = $this->resolvePeople($events->pluck('source_entity_id')->all());

        $rows = [];
        $seen = [];

        foreach ($events as $event) {
            $payload = (array) $event->payload;
            $changes = (array) ($payload['changes'] ?? []);
            $isNewAccount = ($changes['new_account'] ?? null) === true;

            $person = $peopleById[(int) $event->source_entity_id] ?? null;
            $occurredAt = $event->occurred_at instanceof Carbon
                ? $event->occurred_at->toDateString()
                : (string) $event->occurred_at;

            foreach (self::FIELD_TYPE as $field => $type) {
                if (! in_array($type, $wanted, true)) {
                    continue;
                }

                $change = $changes[$field] ?? null;
                if (! is_array($change) || ! isset($change['from'], $change['to'])) {
                    continue;
                }

                // A net-new account carries a current_employer transition that is really a first
                // fill, not a move — drop it so it doesn't read as "left X for Y".
                if ($field === 'current_employer' && $isNewAccount) {
                    continue;
                }

                $fromValue = (string) $change['from'];
                $toValue = (string) $change['to'];

                $dedupeKey = $event->source_entity_id . ':' . $type . ':' . $fromValue . ':' . $toValue . ':' . $occurredAt;
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;

                $rows[] = [
                    'crm' => $this->company->name,
                    'person' => $person['name'] ?? '—',
                    'company' => (string) ($payload['company'] ?? '—'),
                    'type' => $type,
                    'from' => $fromValue,
                    'to' => $toValue,
                    'occurred_at' => $occurredAt,
                    'deliverability' => $person['deliverability'] ?? '—',
                ];

                if ($limit !== null && count($rows) >= $limit) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    /**
     * @param list<string>|null $changeTypes
     *
     * @return list<string>
     */
    private function normalizeChangeTypes(?array $changeTypes): array
    {
        $all = array_values(self::FIELD_TYPE);

        if (empty($changeTypes)) {
            return $all;
        }

        $filtered = array_values(array_intersect($all, array_map('strval', $changeTypes)));

        return $filtered === [] ? $all : $filtered;
    }

    /**
     * @param list<int|string> $ids
     *
     * @return array<int, array{name: string, deliverability: string}>
     */
    private function resolvePeople(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        return People::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->whereIn('id', $ids)
            ->with([
                'contacts' => fn ($query) => $query->where('contacts_types_id', ContactTypeEnum::EMAIL->value),
            ])
            ->get(['id', 'firstname', 'lastname'])
            ->mapWithKeys(fn (People $person): array => [
                (int) $person->getId() => [
                    'name' => $person->getName(),
                    'deliverability' => $this->deliverabilityLabel($person),
                ],
            ])
            ->all();
    }

    private function deliverabilityLabel(People $person): string
    {
        $status = $person->contacts
            ->pluck('validation_status')
            ->map(fn ($value): string => $value instanceof ContactValidationStatusEnum ? $value->value : (string) $value)
            ->reduce(fn (?string $worst, string $current): string => $this->moreSevere($worst, $current));

        return match ($status) {
            ContactValidationStatusEnum::VALID->value => 'Valid',
            ContactValidationStatusEnum::SOFT_BOUNCE->value => 'At risk',
            ContactValidationStatusEnum::HARD_BOUNCE->value => 'Bounced',
            ContactValidationStatusEnum::INVALID->value => 'Invalid',
            default => '—',
        };
    }

    private function moreSevere(?string $worst, string $current): string
    {
        $rank = [
            ContactValidationStatusEnum::VALID->value => 0,
            ContactValidationStatusEnum::SOFT_BOUNCE->value => 1,
            ContactValidationStatusEnum::HARD_BOUNCE->value => 2,
            ContactValidationStatusEnum::INVALID->value => 2,
        ];

        if ($worst === null) {
            return $current;
        }

        return ($rank[$current] ?? 0) >= ($rank[$worst] ?? 0) ? $current : $worst;
    }
}
