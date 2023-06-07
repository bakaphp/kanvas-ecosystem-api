<?php
declare(strict_types=1);

namespace Kanvas\Languages\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
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
        return [
            'name' => fake()->name(),
            'title' => fake()->name(),
            'order' => 0,
            'id' => Languages::latest()->first() ? Languages::latest()->first()->id+1 : 1,
        ];
    }
}
