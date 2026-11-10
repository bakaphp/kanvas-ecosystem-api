<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Enums\PeopleIntrasSyncApprovalTypeEnum;
use Kanvas\Connectors\Intras\Enums\SipgoRecordKindEnum;
use Kanvas\Connectors\Intras\Support\LegacyIdMap;
use Kanvas\Connectors\Intras\Support\SipgoReference;
use Kanvas\Connectors\Intras\Support\SipgoSyncPlan;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Refresh one SIPGO record and everything it needs from SIPGO into Kanvas, right now.
 *
 * The scheduled import runs every 30–60 min over the whole agency; a person reading a version in
 * SIPGO while asking Brain about it sees a newer row than Kanvas has. This walks the graph of ONE
 * root (a version, an event, a participant or a company), collects the legacy ids, and runs the same
 * `Pull*FromIntrasAction`s the full import uses, each narrowed to those ids. No second mapper: a
 * row refreshed here is written exactly as the nightly import would write it, and the pulls keep
 * their `disableWorkflows()`, so a refresh never fires the push-back approval rules.
 *
 * `plan()` reads SIPGO and writes nothing, so a caller can look at `rows()` and decide to run inline
 * (`INLINE_ROW_LIMIT`) or queue `SyncSipgoRecordJob`. `run()` writes. A participant whose Kanvas
 * edit is still awaiting approval is left out of the plan: pulling SIPGO's older value over it would
 * make the person's edit vanish from Kanvas until the approval lands.
 */
class SyncSipgoRecordAction
{
    public const int INLINE_ROW_LIMIT = 300;

    public const string LEDGER_EVENT_TYPE = 'intras.sync.requested';

    private ?Client $client = null;

    public function __construct(
        protected readonly Apps $app,
        protected readonly Companies $company,
        protected readonly Users $user,
        protected readonly SipgoReference $reference,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        return $this->run($this->plan());
    }

    /**
     * @throws ValidationException when the record cannot be refreshed from this company; the message
     *                             is written for the person asking
     */
    public function plan(): SipgoSyncPlan
    {
        $agencyId = (int) $this->company->get(CustomFieldEnum::INTRAS_AGENCY_ID->value);

        if ($agencyId <= 0) {
            throw new ValidationException(sprintf(
                '%s no tiene configurada su agencia de SIPGO (INTRAS_AGENCY_ID); no se puede sincronizar desde aquí.',
                $this->company->name
            ));
        }

        return match ($this->reference->kind) {
            SipgoRecordKindEnum::VERSION => $this->planVersions([$this->reference->legacyId], $agencyId),
            SipgoRecordKindEnum::EVENT => $this->planEvent($agencyId),
            SipgoRecordKindEnum::PARTICIPANT => $this->planParticipant($agencyId),
            SipgoRecordKindEnum::COMPANY => $this->planCompany($agencyId),
            default => throw new ValidationException(sprintf(
                'Sincronizar un(a) %s todavía no está soportado. Se puede sincronizar una versión (VE), '
                . 'un evento (EV), un ejecutivo (PA) o una empresa (EM).',
                $this->reference->kind->label()
            )),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function run(SipgoSyncPlan $plan): array
    {
        $counts = [
            'empresas' => 0,
            'ejecutivos' => 0,
            'eventos' => 0,
            'versiones' => 0,
            'fechas' => 0,
            'facilitadores' => 0,
            'inscripciones' => 0,
        ];

        if ($plan->companyIds !== []) {
            $counts['empresas'] = $this->pullOrganizations($plan);
        }

        if ($plan->participantIds !== []) {
            $counts['ejecutivos'] = $this->pullParticipants($plan);
        }

        if ($plan->versionIds !== []) {
            $events = $this->pullEvents($plan);
            $counts['eventos'] = (int) ($events['events'] ?? 0);
            $counts['versiones'] = (int) ($events['versions'] ?? 0);
            $counts['fechas'] = (int) ($events['dates'] ?? 0);
            $counts['facilitadores'] = $this->pullFacilitatorLinks($plan);
        }

        if ($plan->versionIds !== [] || $plan->root->kind === SipgoRecordKindEnum::PARTICIPANT) {
            $counts['inscripciones'] = $this->pullRegistrations($plan);
        }

        $syncedAt = Carbon::now();
        $root = $this->reference->resolve($this->app, $this->company);
        $root?->set(CustomFieldEnum::INTRAS_SYNCED_AT->value, $syncedAt->toIso8601String());

        $this->recordInLedger($plan, $counts, $root?->getId());

        return [
            ...$counts,
            'omitidos_por_aprobacion' => array_map(
                fn (int $id): string => SipgoReference::codeFor(SipgoRecordKindEnum::PARTICIPANT, $id),
                $plan->skippedParticipantIds
            ),
            'sincronizado_en' => $syncedAt->toIso8601String(),
            'encontrado_en_kanvas' => $root !== null,
        ];
    }

    /**
     * @param list<int> $versionIds
     */
    private function planVersions(array $versionIds, int $agencyId): SipgoSyncPlan
    {
        $versions = $this->client()->table('events_versions')
            ->whereIn('id', $versionIds)
            ->where('is_deleted', 0)
            ->get(['id', 'agencies_id']);

        if ($versions->isEmpty()) {
            throw new ValidationException(sprintf('%s no existe en SIPGO o está eliminada.', $this->reference->code()));
        }

        $ownVersionIds = $versions
            ->filter(fn (object $version): bool => (int) $version->agencies_id === $agencyId)
            ->map(fn (object $version): int => (int) $version->id)
            ->values()
            ->all();

        if ($ownVersionIds === []) {
            throw new ValidationException(sprintf(
                '%s pertenece a otra agencia de SIPGO; desde %s no se puede sincronizar.',
                $this->reference->code(),
                $this->company->name
            ));
        }

        $registrations = $this->client()->table('events_versions_participants')
            ->whereIn('events_versions_id', $ownVersionIds)
            ->where('is_deleted', 0)
            ->get(['participants_id']);

        $participantIds = $registrations
            ->map(fn (object $row): int => (int) $row->participants_id)
            ->unique()
            ->values()
            ->all();

        return $this->buildPlan(
            $agencyId,
            $this->companiesOf($participantIds),
            $participantIds,
            $ownVersionIds,
            $registrations->count()
        );
    }

    private function planEvent(int $agencyId): SipgoSyncPlan
    {
        $versionIds = $this->client()->table('events_versions')
            ->where('events_id', $this->reference->legacyId)
            ->where('agencies_id', $agencyId)
            ->where('is_deleted', 0)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($versionIds === []) {
            throw new ValidationException(sprintf(
                '%s no tiene versiones en %s, o no existe en SIPGO.',
                $this->reference->code(),
                $this->company->name
            ));
        }

        return $this->planVersions($versionIds, $agencyId);
    }

    private function planParticipant(int $agencyId): SipgoSyncPlan
    {
        $participant = $this->client()->table('participants')
            ->where('id', $this->reference->legacyId)
            ->first(['id', 'companies_id']);

        if ($participant === null) {
            throw new ValidationException(sprintf('%s no existe en SIPGO.', $this->reference->code()));
        }

        $registrations = $this->client()->table('events_versions_participants as evp')
            ->join('events_versions as ev', 'ev.id', '=', 'evp.events_versions_id')
            ->where('evp.participants_id', $this->reference->legacyId)
            ->where('evp.is_deleted', 0)
            ->where('ev.agencies_id', $agencyId)
            ->get(['evp.events_versions_id']);

        if ($registrations->isEmpty()) {
            throw new ValidationException(sprintf(
                '%s no tiene inscripciones en %s; su ficha pertenece a otra agencia.',
                $this->reference->code(),
                $this->company->name
            ));
        }

        $companyId = (int) ($participant->companies_id ?? 0);

        return $this->buildPlan(
            $agencyId,
            $companyId > 0 ? [$companyId] : [],
            [(int) $participant->id],
            $registrations->map(fn (object $row): int => (int) $row->events_versions_id)->unique()->values()->all(),
            $registrations->count()
        );
    }

    private function planCompany(int $agencyId): SipgoSyncPlan
    {
        $exists = $this->client()->table('companies')
            ->where('id', $this->reference->legacyId)
            ->where('is_deleted', 0)
            ->exists();

        if (! $exists) {
            throw new ValidationException(sprintf('%s no existe en SIPGO o está eliminada.', $this->reference->code()));
        }

        // Only the people who registered in this agency, the same scope the full import uses.
        $participantIds = $this->client()->table('participants as p')
            ->where('p.companies_id', $this->reference->legacyId)
            ->whereIn('p.id', function (QueryBuilder $sub) use ($agencyId): void {
                $sub->select('evp.participants_id')
                    ->from('events_versions_participants as evp')
                    ->join('events_versions as ev', 'ev.id', '=', 'evp.events_versions_id')
                    ->where('ev.agencies_id', $agencyId)
                    ->where('evp.is_deleted', 0);
            })
            ->pluck('p.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $this->buildPlan(
            $agencyId,
            [$this->reference->legacyId],
            $participantIds,
            [],
            0
        );
    }

    /**
     * @param list<int> $companyIds
     * @param list<int> $participantIds
     * @param list<int> $versionIds
     */
    private function buildPlan(
        int $agencyId,
        array $companyIds,
        array $participantIds,
        array $versionIds,
        int $registrationCount
    ): SipgoSyncPlan {
        $skipped = $this->participantsAwaitingApproval($participantIds);

        return new SipgoSyncPlan(
            root: $this->reference,
            agencyId: $agencyId,
            companyIds: $companyIds,
            participantIds: array_values(array_diff($participantIds, $skipped)),
            versionIds: $versionIds,
            registrationCount: $registrationCount,
            skippedParticipantIds: $skipped,
        );
    }

    /**
     * @param list<int> $participantIds
     *
     * @return list<int>
     */
    private function companiesOf(array $participantIds): array
    {
        if ($participantIds === []) {
            return [];
        }

        return $this->client()->table('participants')
            ->whereIn('id', $participantIds)
            ->whereNotNull('companies_id')
            ->distinct()
            ->pluck('companies_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /**
     * Legacy ids of the participants whose Kanvas People row has an Intras approval still pending.
     *
     * @param list<int> $participantIds
     *
     * @return list<int>
     */
    private function participantsAwaitingApproval(array $participantIds): array
    {
        if ($participantIds === []) {
            return [];
        }

        $peopleIdByLegacyId = LegacyIdMap::load(
            People::class,
            CustomFieldEnum::INTRAS_PARTICIPANT_ID,
            $this->company,
            onlyLegacyIds: $participantIds
        );

        if ($peopleIdByLegacyId === []) {
            return [];
        }

        $legacyByPeopleId = array_flip($peopleIdByLegacyId);

        $pendingPeopleIds = ApprovalRequest::query()
            ->where('system_modules_id', SystemModulesRepository::getByModelName(People::class, $this->app)->getId())
            ->whereIn('entity_id', $peopleIdByLegacyId)
            ->where('status', ApprovalStatusEnum::PENDING)
            ->whereIn('approval_type', array_column(PeopleIntrasSyncApprovalTypeEnum::cases(), 'value'))
            ->where('is_deleted', 0)
            ->pluck('entity_id')
            ->all();

        return array_values(array_unique(array_map(
            fn ($peopleId): int => $legacyByPeopleId[(int) $peopleId],
            $pendingPeopleIds
        )));
    }

    protected function pullOrganizations(SipgoSyncPlan $plan): int
    {
        return new PullOrganizationsFromIntrasAction(
            $this->app,
            $this->company,
            $this->user,
            agencyId: $plan->agencyId,
            onlyCompanyIds: $plan->companyIds,
        )->execute();
    }

    protected function pullParticipants(SipgoSyncPlan $plan): int
    {
        return new PullParticipantsFromIntrasAction(
            $this->app,
            $this->company,
            $this->user,
            agencyId: $plan->agencyId,
            onlyParticipantIds: $plan->participantIds,
        )->execute();
    }

    /**
     * @return array<string, int>
     */
    protected function pullEvents(SipgoSyncPlan $plan): array
    {
        return new PullEventsFromIntrasAction(
            $this->app,
            $this->company,
            $this->user,
            agencyId: $plan->agencyId,
            onlyVersionIds: $plan->versionIds,
        )->execute();
    }

    protected function pullFacilitatorLinks(SipgoSyncPlan $plan): int
    {
        return new PullEventVersionFacilitatorsFromIntrasAction(
            $this->app,
            $this->company,
            $this->user,
            agencyId: $plan->agencyId,
            onlyVersionIds: $plan->versionIds,
        )->execute();
    }

    protected function pullRegistrations(SipgoSyncPlan $plan): int
    {
        $byParticipant = $plan->root->kind === SipgoRecordKindEnum::PARTICIPANT;

        return new PullRegistrationsFromIntrasAction(
            $this->app,
            $this->company,
            $this->user,
            agencyId: $plan->agencyId,
            onlyVersionIds: $byParticipant ? null : $plan->versionIds,
            onlyParticipantIds: $byParticipant ? [$plan->root->legacyId] : null,
        )->execute();
    }

    protected function client(): Client
    {
        return $this->client ??= new Client($this->app);
    }

    /**
     * @param array<string, int> $counts
     */
    private function recordInLedger(SipgoSyncPlan $plan, array $counts, ?int $rootId): void
    {
        try {
            new AppendEventAction(new EventData(
                app: $this->app,
                company: $this->company,
                sourceDomain: 'Connectors',
                eventType: self::LEDGER_EVENT_TYPE,
                status: EventStatusEnum::INFO,
                sourceEntityType: $rootId === null ? null : $this->reference->kind->modelClass(),
                sourceEntityId: $rootId,
                actorType: 'User',
                actorId: $this->user->getId(),
                payload: ['referencia' => $this->reference->code(), 'plan' => $plan->summary(), 'resultado' => $counts],
                occurredAt: Carbon::now(),
            ))->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
