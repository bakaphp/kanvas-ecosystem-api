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
use Kanvas\Connectors\Intras\Mappers\ParticipantMapper;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleType;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;

class PullParticipantsFromIntrasAction
{
    /** @var array<int|string, int> intras_company_id => kanvas_organization_id */
    protected array $organizationIdMap = [];

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

        // participants has no agencies_id and its parent `companies` doesn't either,
        // so the only way to scope by agency is "people who have ever registered for
        // an event in this agency" — via events_versions_participants → events_versions.
        $query = $client->table('participants as p')
            ->where('p.is_deleted', 0)
            ->select('p.*');

        if ($this->agencyId !== null) {
            $agencyId = $this->agencyId;
            $query->whereIn('p.id', function (QueryBuilder $sub) use ($agencyId) {
                $sub->select('evp.participants_id')
                    ->from('events_versions_participants as evp')
                    ->join('events_versions as ev', 'ev.id', '=', 'evp.events_versions_id')
                    ->where('ev.agencies_id', $agencyId)
                    ->where('evp.is_deleted', 0);
            });
        }

        if ($this->lastSyncAt !== null) {
            $query->where('p.updated_at', '>=', $this->lastSyncAt);
        }

        $participantType = PeopleType::firstOrCreate([
            'name' => 'Participant',
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
        ], [
            'users_id' => $this->user->getId(),
            'is_default' => true,
        ]);

        $keyContactType = PeopleType::firstOrCreate([
            'name' => 'Key Contact',
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
        ], [
            'users_id' => $this->user->getId(),
        ]);

        // Preload [intras_company_id => kanvas_organization_id] once. Replaces a
        // per-row whereHas subquery against apps_custom_fields. For 26k people
        // that's ~26k SQL queries collapsed into 1.
        $this->preloadOrganizationMap();

        $count = 0;

        $query->orderBy('p.id')->chunk(500, function ($rows) use (&$count, $participantType, $keyContactType) {
            foreach ($rows as $row) {
                $mapped = ParticipantMapper::fromIntras($row);

                /** @var People $people */
                $people = People::firstOrCreate([
                    'apps_id' => $this->app->getId(),
                    'companies_id' => $this->company->getId(),
                    'firstname' => $mapped['firstname'],
                    'lastname' => $mapped['lastname'],
                ], [
                    'users_id' => $this->user->getId(),
                    'name' => $mapped['firstname'] . ' ' . $mapped['lastname'],
                    'people_types_id' => $row->is_key_participant
                        ? $keyContactType?->getId()
                        : $participantType?->getId(),
                ]);

                if ($row->created_at !== null && $people->wasRecentlyCreated) {
                    $people->created_at = $row->created_at;
                    $people->saveQuietly();
                }

                $people->set(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value, $row->id);

                foreach ($mapped['custom_fields'] as $key => $value) {
                    if ($value !== null) {
                        $people->set($key, $value);
                    }
                }

                $this->linkToOrganization($people, $row->companies_id);

                $count++;
            }
        });

        return $count;
    }

    protected function linkToOrganization(People $people, ?int $intrasCompanyId): void
    {
        if ($intrasCompanyId === null) {
            return;
        }

        $organizationId = $this->organizationIdMap[$intrasCompanyId] ?? null;
        if ($organizationId === null) {
            return;
        }

        // Inline the OrganizationPeople::addPeopleToOrganization() pivot insert so
        // we never need to instantiate the Organization model just to get its id.
        OrganizationPeople::firstOrCreate([
            'organizations_id' => $organizationId,
            'peoples_id' => $people->getId(),
        ], [
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Preload [intras_company_id => kanvas_organization_id]. Single index-backed
     * query against apps_custom_fields for INTRAS_COMPANY_ID rows on Organization.
     */
    protected function preloadOrganizationMap(): void
    {
        $this->organizationIdMap = DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('companies_id', $this->company->getId())
            ->where('model_name', Organization::class)
            ->where('name', CustomFieldEnum::INTRAS_COMPANY_ID->value)
            ->where('is_deleted', 0)
            ->pluck('entity_id', 'value')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
