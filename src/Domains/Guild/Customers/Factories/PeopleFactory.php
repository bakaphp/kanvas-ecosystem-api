<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
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
            'users_id' => 1,
        ];
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

    public function withContacts()
    {
        return $this->afterCreating(function ($person) {
            $person->contacts()->createMany([
                [
                    'contacts_types_id' => 1,
                    'value' => fake()->email,
                    'weight' => 0,
                ],
                [
                    'contacts_types_id' => 2,
                    'value' => fake()->phoneNumber,
                    'weight' => 0,
                ],
            ]);
        });
    }
}
