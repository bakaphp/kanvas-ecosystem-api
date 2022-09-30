<?php
declare(strict_types=1);

namespace Kanvas\Users\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Users\Models\Users;
use Kanvas\Roles\Models\Roles;
use Kanvas\SystemModules\Models\SystemModules;
use Illuminate\Support\Str;

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
        $systemModule = SystemModules::factory(1)->create()->first();
        $role = Roles::factory(1)->create()->first();
        return [
            "firstname" => $this->faker->firstName(),
            "lastname" => $this->faker->lastName(),
            "displayname" => $this->faker->word(),
            "email" => $this->faker->email(),
            "password" => Str::random(10),
            "default_company" => 1,
            "user_active" => 1,
            "roles_id" => $role->id,
            "system_modules_id" => $systemModule->id,
        ];
    }
}
