<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Guild\Customers\Models\Peoples;

class PeopleFactory extends Factory
{
    protected $model = Peoples::class;

    public function definition(): array
    {
        return [
            'users_id' => 1,
            'companies_id' => 1,
            'name' => $this->faker->name,
        ];
    }
}
