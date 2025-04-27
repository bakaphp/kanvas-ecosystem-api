<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Guild\Customers\Models\People;
use Override;

class PeopleFactory extends Factory
{
    protected $model = People::class;

    #[Override]
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

    public function withContacts(bool $canUseFakeInfo = true)
    {
        $email = 'noreply+' . fake()->unique()->userName . '@kanvas.dev';
        $phone = '80935' . fake()->randomNumber(5, true);
        
        return $this->afterCreating(function ($person) use ($canUseFakeInfo, $email, $phone) {
            $person->contacts()->createMany([
                [
                    'contacts_types_id' => 1,
                    'value' => $canUseFakeInfo ? fake()->email : $email,
                    'weight' => 0,
                ],
                [
                    'contacts_types_id' => 2,
                    'value' => $canUseFakeInfo ? fake()->phoneNumber : $phone,
                    'weight' => 0,
                ],
            ]);
        });
    }
}
