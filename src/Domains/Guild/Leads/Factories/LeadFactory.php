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
        $company = Companies::factory()->create();
        $app = app(Apps::class);

        return [
            'firstname' => fake()->firstName,
            'lastname' => fake()->lastName,
            'title' => fake()->name,
            'companies_id' => $company->getId(),
            'companies_branches_id' => AppEnums::GLOBAL_COMPANY_ID->getValue(),
            'users_id' => 1,
            'people_id' => People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create()->getId(),
            'leads_receivers_id' => 0,
            'leads_owner_id' => 1,
        ];
    }
}
