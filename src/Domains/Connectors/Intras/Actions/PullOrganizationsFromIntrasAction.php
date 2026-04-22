<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Mappers\OrganizationMapper;
use Kanvas\Guild\Organizations\Models\Organization;

class PullOrganizationsFromIntrasAction
{
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

        // companies has no agencies_id. Scope by "companies whose employees attended
        // at least one event in this agency": companies → participants → evp → ev.
        // Same chain-subquery pattern used for participants.
        $query = $client->table('companies as co')
            ->where('co.is_deleted', 0)
            ->select('co.*');

        if ($this->agencyId !== null) {
            $agencyId = $this->agencyId;
            $query->whereIn('co.id', function (QueryBuilder $sub) use ($agencyId) {
                $sub->select('p.companies_id')
                    ->from('participants as p')
                    ->join('events_versions_participants as evp', 'evp.participants_id', '=', 'p.id')
                    ->join('events_versions as ev', 'ev.id', '=', 'evp.events_versions_id')
                    ->where('ev.agencies_id', $agencyId)
                    ->where('evp.is_deleted', 0)
                    ->whereNotNull('p.companies_id');
            });
        }

        if ($this->lastSyncAt !== null) {
            $query->where('co.updated_at', '>=', $this->lastSyncAt);
        }

        $count = 0;

        $query->orderBy('co.id')->chunk(500, function ($rows) use (&$count) {
            foreach ($rows as $row) {
                $mapped = OrganizationMapper::fromIntras($row);

                $org = Organization::firstOrCreate([
                    'name' => $mapped['name'],
                    'companies_id' => $this->company->getId(),
                    'apps_id' => $this->app->getId(),
                ], [
                    'users_id' => $this->user->getId(),
                ]);

                $org->set(CustomFieldEnum::INTRAS_COMPANY_ID->value, $row->id);

                foreach ($mapped['custom_fields'] as $key => $value) {
                    if ($value !== null) {
                        $org->set($key, $value);
                    }
                }

                $count++;
            }
        });

        return $count;
    }
}
