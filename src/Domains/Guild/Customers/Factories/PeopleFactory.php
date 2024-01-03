<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Enums\AppEnums;
use Kanvas\Guild\Customers\Models\People;

class PeopleFactory extends Factory
{
    protected $model = People::class;

    public function definition()
    {
        return [
            'firstname' => fake()->firstName,
            'lastname' => fake()->lastName,
            'name' => fake()->name,
            'companies_id' => AppEnums::GLOBAL_COMPANY_ID->getValue(),
            'users_id' => 1,
        ];
    }
}
