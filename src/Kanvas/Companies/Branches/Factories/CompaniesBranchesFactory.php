<?php

namespace Kanvas\Companies\Branches\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Companies\Models\Companies;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Model>
 */
class CompaniesBranchesFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var Apps
     */
    protected $model = CompaniesBranches::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        $user = Users::first();
        $company = Companies::first();
        return [
            'companies_id' => $company->id,
            'users_id' => $user->id,
            'name' => $this->faker->name(),
            'address' => $this->faker->address(),
            'zipcode' => $this->faker->postcode(),
            'email' => $this->faker->email(),
            'phone' => $this->faker->phoneNumber(),
            'is_default' => 1,
            'is_deleted' => 0
        ];
    }
}
