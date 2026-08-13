<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Mappers\ParticipantMapper;
use Kanvas\Guild\Customers\Models\People;

/**
 * Write nivel/área (and the other profile attributes) onto People that were
 * already imported, without re-running the whole participants sync.
 */
class BackfillParticipantProfileFieldsFromIntrasAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected ?int $limit = null,
        protected bool $dryRun = false,
        protected ?Closure $onProgress = null,
    ) {
    }

    /**
     * @return array{scanned: int, updated: int, fields: array<string, int>}
     */
    public function execute(): array
    {
        $client = new Client($this->app);

        $intrasToKanvas = $this->loadParticipantIdMap();
        if ($this->limit !== null) {
            $intrasToKanvas = array_slice($intrasToKanvas, 0, $this->limit, true);
        }

        $lookupNames = PullParticipantsFromIntrasAction::loadLookupNames($client);

        $scanned = 0;
        $updated = 0;
        $fields = [];

        foreach (array_chunk($intrasToKanvas, 500, true) as $chunk) {
            $intrasIds = array_map('intval', array_keys($chunk));

            $contactMap = PullParticipantsFromIntrasAction::loadParticipantContacts($client, $intrasIds);
            $participantRows = $client->table('participants')
                ->whereIn('id', $intrasIds)
                ->get()
                ->keyBy('id');
            $peopleById = $this->loadPeople(array_values($chunk));

            foreach ($chunk as $intrasId => $kanvasPeopleId) {
                $scanned++;

                $row = $participantRows[$intrasId] ?? null;
                $people = $peopleById[$kanvasPeopleId] ?? null;
                if ($row === null || $people === null) {
                    continue;
                }

                $profile = ParticipantMapper::profileFields($row, $contactMap[$intrasId] ?? [], $lookupNames);
                if ($profile === []) {
                    continue;
                }

                foreach ($profile as $name => $value) {
                    $fields[$name] = ($fields[$name] ?? 0) + 1;

                    if (! $this->dryRun) {
                        $people->set($name, $value);
                    }
                }

                $updated++;
            }

            if ($this->onProgress !== null) {
                ($this->onProgress)($scanned, $updated);
            }
        }

        return ['scanned' => $scanned, 'updated' => $updated, 'fields' => $fields];
    }

    /**
     * @return array<int, int> intras_participant_id => kanvas_people_id
     */
    protected function loadParticipantIdMap(): array
    {
        return DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('companies_id', $this->company->getId())
            ->where('model_name', People::class)
            ->where('name', CustomFieldEnum::INTRAS_PARTICIPANT_ID->value)
            ->where('is_deleted', 0)
            ->pluck('entity_id', 'value')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param list<int> $peopleIds
     *
     * @return array<int, People>
     */
    protected function loadPeople(array $peopleIds): array
    {
        /** @var Collection<int, People> $people */
        $people = People::query()
            ->whereIn('id', $peopleIds)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->get();

        return $people->keyBy(fn (People $p) => $p->getId())->all();
    }
}
