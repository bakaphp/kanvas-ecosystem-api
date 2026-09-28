<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Mappers\RegistrationMapper;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Guild\Customers\Models\People;

class PullRegistrationsFromIntrasAction
{
    /** @var array<int|string, int> intras_event_version_id => kanvas_event_version_id */
    protected array $eventVersionIdMap = [];

    /** @var array<int|string, int> intras_participant_id => kanvas_people_id */
    protected array $peopleIdMap = [];

    /** @var array<int|string, int> intras_inscription_type_id => kanvas_participant_type_id */
    protected array $participantTypeIdMap = [];

    /** @var array<int, int> intras events_versions_participants.id => kanvas event_version_participant id */
    protected array $registrationIdMap = [];

    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user,
        protected ?string $lastSyncAt = null,
        protected ?int $agencyId = null
    ) {
    }

    public function execute(): int
    {
        $client = new Client($this->app);

        // events_versions_participants has no agencies_id — join to events_versions
        // (which does) so we never pull rows that belong to other agencies. Without
        // this, a single-agency smoke test scans the full ~135k-row table.
        $query = $client->table('events_versions_participants as evp')
            ->where('evp.is_deleted', 0)
            ->select('evp.*');

        if ($this->agencyId !== null) {
            $query->join('events_versions as ev', 'ev.id', '=', 'evp.events_versions_id')
                ->where('ev.agencies_id', $this->agencyId);
        }

        if ($this->lastSyncAt !== null) {
            $query->where('evp.updated_at', '>=', $this->lastSyncAt);
        }

        // Preload Intras-ID → Kanvas-ID maps once. Replaces ~3 whereHas subqueries
        // per row with O(1) array lookups. For 69k registrations that's ~208k SQL
        // queries collapsed into 3.
        $this->preloadMaps();

        $defaultThemeArea = ThemeArea::where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->first();

        $count = 0;

        $query->orderBy('evp.id')->chunk(500, function ($rows) use (&$count, $client, $defaultThemeArea) {
            $participantIds = array_values(array_unique(array_map(
                fn ($r) => (int) $r->participants_id,
                $rows->all()
            )));
            $programEnrolments = self::loadProgramEnrolments($client, $participantIds);

            foreach ($rows as $row) {
                $kanvasEventVersionId = $this->eventVersionIdMap[$row->events_versions_id] ?? null;
                $kanvasPeopleId = $this->peopleIdMap[$row->participants_id] ?? null;

                if ($kanvasEventVersionId === null || $kanvasPeopleId === null) {
                    continue;
                }

                // `participants.slug` is NOT NULL with no default and the model carries no
                // SlugTrait, so it has to be set explicitly — same trap as facilitators.slug.
                /** @var Participant $participant */
                $participant = Participant::firstOrCreate([
                    'people_id' => $kanvasPeopleId,
                    'apps_id' => $this->app->getId(),
                    'companies_id' => $this->company->getId(),
                ], [
                    'users_id' => $this->user->getId(),
                    'theme_area_id' => $defaultThemeArea?->getId() ?? 0,
                    'slug' => Str::slug('participant-' . $kanvasPeopleId),
                ]);

                if ($row->created_at !== null && $participant->wasRecentlyCreated) {
                    $participant->created_at = $row->created_at;
                    $participant->saveQuietly();
                }

                $kanvasParticipantTypeId = $row->inscriptions_types_id !== null
                    ? ($this->participantTypeIdMap[$row->inscriptions_types_id] ?? null)
                    : null;

                $mapped = RegistrationMapper::fromIntras($row);

                // Identified by the legacy row id, not (version, participant): SIPGO lets the same
                // person hold two registrations for one version — a courtesy seat plus a paid one,
                // say — and the composite key silently collapsed them into a single row,
                // undercounting seats in every report that sums them.
                $evp = $this->resolveRegistration((int) $row->id, [
                    'event_version_id' => $kanvasEventVersionId,
                    'participant_id' => $participant->getId(),
                    'participant_type_id' => $kanvasParticipantTypeId,
                    'ticket_price' => $mapped['ticket_price'],
                    'discount' => $mapped['discount'],
                    'invoice_date' => $mapped['invoice_date'],
                    'metadata' => $mapped['metadata'],
                ]);

                // participants_programs is keyed on (participant, version) — the registration
                // grain — so the enrolment rides on the registration itself.
                $enrolment = $programEnrolments[$row->participants_id . ':' . $row->events_versions_id] ?? null;

                if ($enrolment !== null) {
                    $evp->set('programa', $enrolment['programa']);
                    $evp->set('programa_completado', $enrolment['completado']);
                }

                if ($row->created_at !== null && $evp->wasRecentlyCreated) {
                    $evp->created_at = $row->created_at;
                    if ($row->updated_at !== null) {
                        $evp->updated_at = $row->updated_at;
                    }
                    $evp->saveQuietly();
                }

                $count++;
            }
        });

        return $count;
    }

    /**
     * Preload [intras_id => kanvas_id] maps for the 3 lookups this action needs.
     * Three queries against `apps_custom_fields`, all hitting the composite index
     * `idx_company_model_name_value_is_deleted`.
     */
    protected function preloadMaps(): void
    {
        $companyId = $this->company->getId();

        $this->eventVersionIdMap = $this->loadIntrasMap(
            $companyId,
            EventVersion::class,
            CustomFieldEnum::INTRAS_EVENT_VERSION_ID->value,
        );

        $this->peopleIdMap = $this->loadIntrasMap(
            $companyId,
            People::class,
            CustomFieldEnum::INTRAS_PARTICIPANT_ID->value,
        );

        $this->participantTypeIdMap = $this->loadIntrasMap(
            $companyId,
            ParticipantType::class,
            CustomFieldEnum::INTRAS_EVENT_ID->value,
        );

        // Makes re-imports idempotent now that registrations are keyed on the legacy row id
        // rather than (version, participant).
        //
        // Deliberately NOT company-scoped: EventVersionParticipant carries
        // NoCompanyRelationshipTrait, so its custom fields are written with companies_id = 0.
        // Filtering on the importing company finds nothing and every run creates a second copy
        // of every registration — which is exactly what happened before this was fixed.
        $this->registrationIdMap = $this->loadIntrasMap(
            null,
            EventVersionParticipant::class,
            CustomFieldEnum::INTRAS_REGISTRATION_ID->value,
        );
    }

    /**
     * Programme enrolment per (participant, version).
     *
     * `participants_programs` is `(participant, program, event, version, completed)` — exactly the
     * registration grain — so it becomes custom fields on the registration rather than a pivot of
     * its own. No new Kanvas tables.
     *
     * @param list<int> $participantIds
     *
     * @return array<string, array{programa: string, completado: bool}> "participantId:versionId"
     */
    public static function loadProgramEnrolments(Client $client, array $participantIds): array
    {
        if ($participantIds === []) {
            return [];
        }

        $rows = $client->table('participants_programs as pp')
            ->join('programs as p', 'p.id', '=', 'pp.programs_id')
            ->whereIn('pp.participants_id', $participantIds)
            ->where('pp.is_deleted', 0)
            ->whereNotNull('pp.events_versions_id')
            ->select('pp.participants_id', 'pp.events_versions_id', 'pp.completed', 'p.name')
            ->get();

        $enrolments = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row->name ?? ''));

            if ($name === '') {
                continue;
            }

            $key = $row->participants_id . ':' . $row->events_versions_id;
            $enrolments[$key] = [
                'programa' => $name,
                'completado' => (bool) ($row->completed ?? false),
            ];
        }

        return $enrolments;
    }

    /**
     * Find-or-create a registration by its SIPGO `events_versions_participants.id`.
     *
     * EventVersionParticipant has no apps_id/companies_id of its own (NoApp/NoCompany traits), so
     * the legacy id lives in a custom field and the id map is preloaded once — a per-row lookup
     * would be one query per registration.
     *
     * @param array<string, mixed> $attributes
     */
    protected function resolveRegistration(int $legacyId, array $attributes): EventVersionParticipant
    {
        $existingId = $this->registrationIdMap[$legacyId] ?? null;

        if ($existingId !== null) {
            /** @var EventVersionParticipant|null $existing */
            // withTrashed(): Event's BaseModel carries a global `is_deleted = 0` scope, so a
            // registration soft-deleted on the Kanvas side would be invisible here and the map
            // would create a second row for the same legacy id on every run.
            $existing = EventVersionParticipant::withTrashed()->find($existingId);

            if ($existing !== null) {
                return $existing;
            }
        }

        /** @var EventVersionParticipant $evp */
        $evp = EventVersionParticipant::create($attributes);
        $evp->set(CustomFieldEnum::INTRAS_REGISTRATION_ID->value, $legacyId);

        $this->registrationIdMap[$legacyId] = $evp->getId();

        return $evp;
    }

    /**
     * @return array<int|string, int>
     */
    protected function loadIntrasMap(?int $companyId, string $modelClass, string $customFieldName): array
    {
        return DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->when($companyId !== null, fn ($query) => $query->where('companies_id', $companyId))
            ->where('model_name', $modelClass)
            ->where('name', $customFieldName)
            ->where('is_deleted', 0)
            ->pluck('entity_id', 'value')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
