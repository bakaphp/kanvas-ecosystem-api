<?php
declare(strict_types=1);

namespace Kanvas\Users\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Kanvas\Users\Models\Users;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Model>
 */
class UsersFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var Users
     */
    protected $model = Users::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'firstname' => $this->faker->firstName(),
            'lastname' => $this->faker->lastName(),
            'displayname' => $this->faker->word(),
            'email' => $this->faker->email(),
            'password' => Str::random(10),
            'default_company' => 0,
            'user_active' => 1,
            'roles_id' => 1,
            'system_modules_id' => 1,
        ];
    }
}
