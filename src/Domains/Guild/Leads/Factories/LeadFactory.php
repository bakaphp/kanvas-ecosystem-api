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
                return app(Apps::class)->getId();
            },
            'companies_id' => function (array $attributes) {
                return Companies::factory()->create()->getKey();
            },
            'people_id' => function (array $attributes) {
                // We can't rely on $attributes here because other closures haven't been resolved yet
                // So we'll create the dependencies directly
                $appId = $attributes['apps_id'] ?? app(Apps::class)->getId();
                $companyId = $attributes['companies_id'] ?? Companies::factory()->create()->getKey();

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
            $branch = Companies::find($companyId)->defaultBranch();

            return [
                'companies_id' => $companyId,
                'companies_branches_id' => $branch->first()->getId(),
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

    /**
     * Create a lead with specified app and company, ensuring people is created with the same IDs
     */
    public function withAppAndCompany(int $appId, int $companyId)
    {
        return $this->state(function (array $attributes) use ($appId, $companyId) {
            $peopleId = People::factory()
                ->withAppId($appId)
                ->withCompanyId($companyId)
                ->withContacts()
                ->create()
                ->getId();

            return [
                'apps_id' => $appId,
                'companies_id' => $companyId,
                'people_id' => $peopleId,
            ];
        });
    }
}
