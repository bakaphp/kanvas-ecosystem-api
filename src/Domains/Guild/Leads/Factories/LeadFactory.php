<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;

class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition()
    {
        $app = app(Apps::class);
        $appId = $this->states['apps_id'] ?? $app->getId(); // Use the provided app ID if set
        $companyId = $this->states['companies_id'] ?? Companies::factory()->create()->getId(); // Use the provided company ID if set

        return [
            'firstname' => fake()->firstName,
            'lastname' => fake()->lastName,
            'title' => fake()->name,
            'companies_branches_id' => AppEnums::GLOBAL_COMPANY_ID->getValue(),
            'users_id' => 1,
            'leads_receivers_id' => 0,
            'leads_owner_id' => 1,
            'apps_id' => $appId,
            'companies_id' => $companyId,
            'people_id' => People::factory()
                            ->withAppId($appId)
                            ->withCompanyId($companyId)
                            ->create()
                            ->getId(),
        ];
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
}
