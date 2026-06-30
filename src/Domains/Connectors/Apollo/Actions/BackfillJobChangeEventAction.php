<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Apollo\Enums\ConfigurationEnum;
use Kanvas\Connectors\Apollo\Services\PersonCurrentEmployerService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as LedgerEventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Throwable;

/**
 * Replays a single person's historical Apollo job change (the APOLLO_LAST_JOB_CHANGE
 * blob) into the ledger as a `people.enriched` event — but only when it's a genuine
 * move, and only once. The command BackfillJobChangeEventsCommand selects the people;
 * this holds the per-person diff / dedup / emit so it's unit-testable without the
 * cross-connection custom-field selection.
 */
class BackfillJobChangeEventAction
{
    public const string EMITTED = 'emitted';
    public const string WOULD_EMIT = 'would_emit';
    public const string SKIPPED_NO_CHANGE = 'skipped_no_change';
    public const string SKIPPED_DUPLICATE = 'skipped_duplicate';
    public const string SKIPPED_PAST_EMPLOYER = 'skipped_past_employer';

    public function __construct(
        protected readonly People $person,
        protected readonly Apps $app,
        protected readonly Companies $company,
    ) {
    }

    /**
     * @return self::EMITTED|self::WOULD_EMIT|self::SKIPPED_NO_CHANGE|self::SKIPPED_DUPLICATE|self::SKIPPED_PAST_EMPLOYER
     */
    public function execute(bool $dryRun = false): string
    {
        $change = (array) ($this->person->get(ConfigurationEnum::APOLLO_LAST_JOB_CHANGE->value) ?? []);

        $fromCompany = trim((string) ($change['from_company'] ?? ''));
        $toCompany = trim((string) ($change['to_company'] ?? ''));
        $employerMove = EnrichPeopleFromApolloAction::isRealTransition($fromCompany, $toCompany);

        // The stored blob was written by the same buggy enrichment that mislabeled a PAST
        // job as the current employer (Alpha → Baninter). Never re-emit such a move — guard
        // on the same genuine-current-employer rule the cleanup uses.
        if ($employerMove && ! new PersonCurrentEmployerService()->isGenuineCurrentEmployer((int) $this->person->getId(), $toCompany)) {
            return self::SKIPPED_PAST_EMPLOYER;
        }

        $diff = $this->buildDiff($change, $employerMove);
        if ($diff === []) {
            return self::SKIPPED_NO_CHANGE;
        }

        if ($this->alreadyInLedger($toCompany)) {
            return self::SKIPPED_DUPLICATE;
        }

        if ($dryRun) {
            return self::WOULD_EMIT;
        }

        $this->emit($change, $diff, $toCompany);

        return self::EMITTED;
    }

    /**
     * Mirror the live emitter's diff shape so backfilled rows read identically to live ones.
     *
     * @param array<string, mixed> $change
     *
     * @return array<string, array{from: string, to: string}>
     */
    private function buildDiff(array $change, bool $employerMove): array
    {
        $diff = [];

        if ($employerMove) {
            $diff['current_employer'] = [
                'from' => trim((string) ($change['from_company'] ?? '')),
                'to' => trim((string) ($change['to_company'] ?? '')),
            ];
        }

        $fromTitle = trim((string) ($change['from_title'] ?? ''));
        $toTitle = trim((string) ($change['to_title'] ?? ''));
        if (EnrichPeopleFromApolloAction::isRealTransition($fromTitle, $toTitle)) {
            $diff['title'] = ['from' => $fromTitle, 'to' => $toTitle];
        }

        return $diff;
    }

    /**
     * Has the live emitter (or a prior backfill run) already captured this move? Matched
     * on the destination employer in the payload — case-insensitive, so re-running never
     * doubles a row. A recorded job change always carries an employer move, so
     * `current_employer.to` is always present to match on.
     */
    private function alreadyInLedger(string $toCompany): bool
    {
        return Event::query()
            ->fromApp($this->app)
            ->where('event_type', 'people.enriched')
            ->where('source_entity_type', People::class)
            ->where('source_entity_id', (int) $this->person->getId())
            ->whereRaw(
                "LOWER(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.changes.current_employer.to'))) = ?",
                [mb_strtolower($toCompany)],
            )
            ->exists();
    }

    /**
     * @param array<string, mixed>                            $change
     * @param array<string, array{from: string, to: string}> $diff
     */
    private function emit(array $change, array $diff, string $toCompany): void
    {
        new AppendEventAction(
            new LedgerEventData(
                app: $this->app,
                company: $this->company,
                sourceDomain: 'Guild',
                eventType: 'people.enriched',
                status: EventStatusEnum::INFO,
                sourceEntityType: People::class,
                sourceEntityId: (int) $this->person->getId(),
                actorType: 'System',
                payload: [
                    'source' => 'apollo',
                    'company' => $toCompany,
                    'name' => trim("{$this->person->firstname} {$this->person->lastname}"),
                    'changed_fields' => array_keys($diff),
                    'changes' => $diff,
                    'backfilled' => true,
                ],
                occurredAt: $this->resolveOccurredAt($change),
            ),
        )->execute();
    }

    /**
     * The original change time. Prefer the ISO `changed_at` on the blob; fall back to the
     * `APOLLO_JOB_CHANGED_AT` epoch marker, then to now — never let a bad timestamp abort
     * the backfill.
     *
     * @param array<string, mixed> $change
     */
    private function resolveOccurredAt(array $change): Carbon
    {
        $changedAt = (string) ($change['changed_at'] ?? '');
        if ($changedAt !== '') {
            try {
                return Carbon::parse($changedAt);
            } catch (Throwable) {
                // fall through to the marker / now
            }
        }

        $marker = $this->person->get(ConfigurationEnum::APOLLO_JOB_CHANGED_AT->value);
        if (! empty($marker)) {
            return Carbon::createFromTimestamp((int) $marker);
        }

        return Carbon::now();
    }
}
