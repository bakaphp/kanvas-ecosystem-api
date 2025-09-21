<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Override;

class LeadFactory extends Factory
{
    protected $model = Lead::class;

    #[Override]
    public function definition(): array
    {
        return [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'title' => fake()->name(),
            'companies_branches_id' => AppEnums::GLOBAL_COMPANY_ID->getValue(),
            'users_id' => 1,
            'leads_receivers_id' => 0,
            'leads_owner_id' => 1,
            'apps_id' => function (array $attributes) {
                if (isset($attributes['apps_id']) && $attributes['apps_id']) {
                    return (int) $attributes['apps_id'];
                }

                return app(Apps::class)->getId(); // tu default
            },
            'companies_id' => function (array $attributes) {
                if (! empty($attributes['companies_id'])) {
                    return (int) $attributes['companies_id'];
                }

                return Companies::factory()->create()->getKey();
            },
            'people_id' => function (array $attributes) {
                $appId = (int) $attributes['apps_id'];
                $companyId = (int) $attributes['companies_id'];

                return People::factory()
                    ->withAppId($appId)
                    ->withCompanyId($companyId)
                    ->withContacts()
                    ->create()
                    ->getId();
            },
        ];
    }

    public function withPeopleId(int $peopleId)
    {
        return $this->state(function (array $attributes) use ($peopleId) {
            return [
                'people_id' => $peopleId,
            ];
        });
    }

    public function withUserId(int $userId)
    {
        return $this->state(function (array $attributes) use ($userId) {
            return [
                'users_id' => $userId,
            ];
        });
    }

    public function withAppId(int $appId)
    {
        return $this->state(function (array $attributes) use ($appId) {
            return [
                'apps_id' => $appId,
            ];
        });
    }

    public function withCompanyId(int $companyId)
    {
        return $this->state(function (array $attributes) use ($companyId) {
            return [
                'companies_id' => $companyId,
            ];
        });
    }

    public function withReceiverId(int $receiverId)
    {
        return $this->state(function (array $attributes) use ($receiverId) {
            return [
                'leads_receivers_id' => $receiverId,
            ];
        });
    }
}
