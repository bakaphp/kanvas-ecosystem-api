<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Mappers\EntitlementMapper;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Participants\Models\ParticipantPassMotive;
use Kanvas\Guild\Customers\Models\People;

/**
 * Per-participant courtesy passes → the existing `participant_passes`.
 *
 * `courtsey_passes` was entirely unimported, which left the Cortesía/Intercambios tab with no
 * data at all. The company-level *pools* (`companies_courtsey_passes`) are a different shape and
 * live as JSON on the Organization — see PullEntitlementsFromIntrasAction.
 *
 * Runs after events, participants and registrations: it resolves all of them by legacy id.
 */
class PullCourtesyPassesFromIntrasAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user,
        protected ?int $agencyId = null
    ) {
    }

    public function execute(): int
    {
        $client = new Client($this->app);

        $eventIdMap = $this->legacyIdMap(Event::class, CustomFieldEnum::INTRAS_EVENT_ID->value);
        $versionIdMap = $this->legacyIdMap(EventVersion::class, CustomFieldEnum::INTRAS_EVENT_VERSION_ID->value);
        $peopleIdMap = $this->legacyIdMap(People::class, CustomFieldEnum::INTRAS_PARTICIPANT_ID->value);

        if ($eventIdMap === [] || $peopleIdMap === []) {
            return 0;
        }

        $motiveIdMap = $this->syncMotives($client);
        $defaultMotive = $this->defaultMotive();

        $query = $client->table('courtsey_passes')->where('is_deleted', 0);

        if ($this->agencyId !== null) {
            $query->where('agencies_id', $this->agencyId);
        }

        $count = 0;

        $query->orderBy('id')->chunk(500, function ($rows) use (
            &$count,
            $eventIdMap,
            $versionIdMap,
            $peopleIdMap,
            $motiveIdMap,
            $defaultMotive
        ) {
            foreach ($rows as $row) {
                $eventId = $eventIdMap[(int) ($row->events_id ?? 0)] ?? null;
                $versionId = $versionIdMap[(int) ($row->events_versions_id ?? 0)] ?? null;
                $peopleId = $peopleIdMap[(int) ($row->participants_id ?? 0)] ?? null;

                // event_version_id, event_id and participant_pass_motive_id are all NOT NULL with
                // FKs, so a pass missing any of them is skipped rather than written broken.
                if ($eventId === null || $versionId === null || $peopleId === null) {
                    continue;
                }

                $participant = DB::connection('event')->table('participants')
                    ->where('people_id', $peopleId)
                    ->where('apps_id', $this->app->getId())
                    ->where('companies_id', $this->company->getId())
                    ->value('id');

                if ($participant === null) {
                    continue;
                }

                $mapped = EntitlementMapper::participantPassFromIntras($row);
                $motiveId = $motiveIdMap[(int) ($row->courtsey_passes_motives_id ?? 0)] ?? $defaultMotive;

                if ($motiveId === null) {
                    continue;
                }

                /** @var ParticipantPass $pass */
                $pass = ParticipantPass::firstOrCreate([
                    'apps_id' => $this->app->getId(),
                    'companies_id' => $this->company->getId(),
                    'code' => 'INTRAS-' . $row->id,
                ], [
                    'users_id' => $this->user->getId(),
                    'event_id' => $eventId,
                    'event_version_id' => $versionId,
                    'participant_id' => $participant,
                    'participant_pass_motive_id' => $motiveId,
                    // NOT NULL with no default; the legacy row may carry none.
                    'expiration_date' => $mapped['expiration_date'] ?? '9999-12-31',
                ]);

                $pass->update(array_filter([
                    'issue_date' => $mapped['issue_date'],
                    'used_date' => $mapped['used_date'],
                    'payload' => $mapped['payload'] === [] ? null : $mapped['payload'],
                ], fn ($value) => $value !== null));

                $count++;
            }
        });

        return $count;
    }

    /**
     * `courtsey_passes_motives` → ParticipantPassMotive, matched on name.
     *
     * @return array<int, int> legacy motive id => kanvas motive id
     */
    protected function syncMotives(Client $client): array
    {
        $map = [];

        foreach ($client->table('courtsey_passes_motives')->where('is_deleted', 0)->get() as $row) {
            $name = trim((string) $row->name);

            if ($name === '') {
                continue;
            }

            /** @var ParticipantPassMotive $motive */
            $motive = ParticipantPassMotive::firstOrCreate([
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'name' => $name,
            ], [
                'users_id' => $this->user->getId(),
            ]);

            $map[(int) $row->id] = (int) $motive->getId();
        }

        return $map;
    }

    /**
     * A legacy pass may carry no motive, but the column is NOT NULL — so one is provided rather
     * than dropping the pass.
     */
    protected function defaultMotive(): ?int
    {
        /** @var ParticipantPassMotive $motive */
        $motive = ParticipantPassMotive::firstOrCreate([
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'name' => 'Sin motivo',
        ], [
            'users_id' => $this->user->getId(),
        ]);

        return (int) $motive->getId();
    }

    /**
     * @return array<int, int>
     */
    protected function legacyIdMap(string $modelClass, string $customFieldName): array
    {
        return DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('companies_id', $this->company->getId())
            ->where('model_name', $modelClass)
            ->where('name', $customFieldName)
            ->where('is_deleted', 0)
            ->pluck('entity_id', 'value')
            ->mapWithKeys(fn ($kanvasId, $legacyId) => [(int) $legacyId => (int) $kanvasId])
            ->all();
    }
}
