<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
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

        $query = $client->table('companies')
            ->where('is_deleted', 0);

        if ($this->lastSyncAt !== null) {
            $query->where('updated_at', '>=', $this->lastSyncAt);
        }

        $count = 0;

        $query->orderBy('id')->chunk(500, function ($rows) use (&$count) {
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
