<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Facilitators\Models\EventVersionFacilitator;
use Kanvas\Event\Facilitators\Models\Facilitator;

/**
 * Link facilitators to the event versions they taught.
 *
 * Nothing populated `event_version_facilitators`, so no version had a facilitator and the whole
 * facilitator-by-event filter set on the Gestor — facilitator on Ejecutivos, and the six
 * correlated conditions on the Facilitadores tab — matched nothing.
 *
 * Runs after events and facilitators: it resolves both sides through their legacy-id maps.
 */
class PullEventVersionFacilitatorsFromIntrasAction
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
        // Resolve both sides before opening the legacy connection: with nothing mapped there is
        // no work to do, and no reason to require the SIPGO credentials to find that out.
        $versionIdMap = $this->legacyIdMap(EventVersion::class, CustomFieldEnum::INTRAS_EVENT_VERSION_ID->value);
        $facilitatorIdMap = $this->legacyIdMap(Facilitator::class, CustomFieldEnum::INTRAS_FACILITATOR_ID->value);

        if ($versionIdMap === [] || $facilitatorIdMap === []) {
            return 0;
        }

        $client = new Client($this->app);

        $query = $client->table('events_versions_facilitators as evf')
            ->where('evf.is_deleted', 0)
            ->select('evf.events_versions_id', 'evf.facilitators_id');

        if ($this->agencyId !== null) {
            $agencyId = $this->agencyId;
            $query->whereIn('evf.events_versions_id', function (QueryBuilder $sub) use ($agencyId) {
                $sub->select('ev.id')
                    ->from('events_versions as ev')
                    ->where('ev.agencies_id', $agencyId);
            });
        }

        $count = 0;

        $query->orderBy('evf.id')->chunk(500, function ($rows) use (&$count, $versionIdMap, $facilitatorIdMap) {
            foreach ($rows as $row) {
                $versionId = $versionIdMap[(int) $row->events_versions_id] ?? null;
                $facilitatorId = $facilitatorIdMap[(int) $row->facilitators_id] ?? null;

                // A link whose version or facilitator never imported is skipped rather than
                // written half-resolved — a null on either side breaks the non-null GraphQL
                // relation the Facilitadores tab reads through.
                if ($versionId === null || $facilitatorId === null) {
                    continue;
                }

                EventVersionFacilitator::firstOrCreate([
                    'event_version_id' => $versionId,
                    'facilitator_id' => $facilitatorId,
                ]);

                $count++;
            }
        });

        return $count;
    }

    /**
     * @return array<int, int> legacy id => kanvas id
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
