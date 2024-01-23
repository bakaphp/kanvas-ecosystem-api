<?php

declare(strict_types=1);

namespace Kanvas\Languages\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Languages\Models\Languages;

class LanguagesFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var Languages
     */
    protected $model = Languages::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        //two random number to generate a random id
        $id = random_int(1, 10) . date('s');

        //@todo modify id of language to be increment
        return [
            'name' => fake()->name(),
            'title' => fake()->name(),
            'order' => 0,
            'id' => $id,
        ];
    }
}
