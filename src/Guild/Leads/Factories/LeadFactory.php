<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Guild\Leads\Models\Leads;

class LeadFactory extends Factory
{
    protected $model = Leads::class;

    public function definition(): array
    {
        return [
            'users_id' => 1,
            'companies_id' => 1,
            'companies_branches_id' => 1,
            'people_id' => 1,
            'leads_receivers_id' => 0,
            'leads_owner_id' => 1,
            'title' => $this->faker->name,
        ];
    }
}
