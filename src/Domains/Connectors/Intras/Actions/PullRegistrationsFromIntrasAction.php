<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
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

        $query->orderBy('evp.id')->chunk(500, function ($rows) use (&$count, $defaultThemeArea) {
            foreach ($rows as $row) {
                $kanvasEventVersionId = $this->eventVersionIdMap[$row->events_versions_id] ?? null;
                $kanvasPeopleId = $this->peopleIdMap[$row->participants_id] ?? null;

                if ($kanvasEventVersionId === null || $kanvasPeopleId === null) {
                    continue;
                }

                /** @var Participant $participant */
                $participant = Participant::firstOrCreate([
                    'people_id' => $kanvasPeopleId,
                    'apps_id' => $this->app->getId(),
                    'companies_id' => $this->company->getId(),
                ], [
                    'users_id' => $this->user->getId(),
                    'theme_area_id' => $defaultThemeArea?->getId() ?? 0,
                ]);

                if ($row->created_at !== null && $participant->wasRecentlyCreated) {
                    $participant->created_at = $row->created_at;
                    $participant->saveQuietly();
                }

                $kanvasParticipantTypeId = $row->inscriptions_types_id !== null
                    ? ($this->participantTypeIdMap[$row->inscriptions_types_id] ?? null)
                    : null;

                $mapped = RegistrationMapper::fromIntras($row);

                /** @var EventVersionParticipant $evp */
                $evp = EventVersionParticipant::firstOrCreate([
                    'event_version_id' => $kanvasEventVersionId,
                    'participant_id' => $participant->getId(),
                ], [
                    'participant_type_id' => $kanvasParticipantTypeId,
                    'ticket_price' => $mapped['ticket_price'],
                    'discount' => $mapped['discount'],
                    'invoice_date' => $mapped['invoice_date'],
                    'metadata' => $mapped['metadata'],
                ]);

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
    }

    /**
     * @return array<int|string, int>
     */
    protected function loadIntrasMap(int $companyId, string $modelClass, string $customFieldName): array
    {
        return DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('companies_id', $companyId)
            ->where('model_name', $modelClass)
            ->where('name', $customFieldName)
            ->where('is_deleted', 0)
            ->pluck('entity_id', 'value')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
