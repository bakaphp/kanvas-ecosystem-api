<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
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

        $query = $client->table('participants')
            ->where('is_deleted', 0);

        if ($this->lastSyncAt !== null) {
            $query->where('updated_at', '>=', $this->lastSyncAt);
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

        $count = 0;

        $query->orderBy('id')->chunk(500, function ($rows) use (&$count, $participantType, $keyContactType) {
            foreach ($rows as $row) {
                $mapped = ParticipantMapper::fromIntras($row);

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

        $org = Organization::where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereHas(
                'customFields',
                fn (Builder $q) => $q->where('name', CustomFieldEnum::INTRAS_COMPANY_ID->value)->where('value', $intrasCompanyId)
            )
            ->first();

        if ($org) {
            OrganizationPeople::addPeopleToOrganization($org, $people);
        }
    }
}
