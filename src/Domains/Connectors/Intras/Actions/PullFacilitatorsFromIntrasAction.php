<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Mappers\FacilitatorMapper;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleType;

class PullFacilitatorsFromIntrasAction
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

        $query = $client->table('facilitators')
            ->where('is_deleted', 0);

        if ($this->agencyId !== null) {
            $query->where('agencies_id', $this->agencyId);
        }

        if ($this->lastSyncAt !== null) {
            $query->where('updated_at', '>=', $this->lastSyncAt);
        }

        $facilitatorType = PeopleType::firstOrCreate([
            'name' => 'Facilitator',
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
        ], [
            'users_id' => $this->user->getId(),
        ]);

        $count = 0;

        $query->orderBy('id')->chunk(500, function ($rows) use (&$count, $facilitatorType) {
            foreach ($rows as $row) {
                $mapped = FacilitatorMapper::fromIntras($row);

                /** @var People $people */
                $people = People::firstOrCreate([
                    'apps_id' => $this->app->getId(),
                    'companies_id' => $this->company->getId(),
                    'firstname' => $mapped['firstname'],
                    'lastname' => $mapped['lastname'],
                ], [
                    'users_id' => $this->user->getId(),
                    'name' => $mapped['firstname'] . ' ' . $mapped['lastname'],
                    'people_types_id' => $facilitatorType?->getId(),
                ]);

                if ($row->created_at !== null && $people->wasRecentlyCreated) {
                    $people->created_at = $row->created_at;
                    $people->saveQuietly();
                }

                $people->set(CustomFieldEnum::INTRAS_FACILITATOR_ID->value, $row->id);

                foreach ($mapped['custom_fields'] as $key => $value) {
                    if ($value !== null) {
                        $people->set($key, $value);
                    }
                }

                $count++;
            }
        });

        return $count;
    }
}
