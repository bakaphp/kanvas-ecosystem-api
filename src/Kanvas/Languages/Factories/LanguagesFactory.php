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
        //two random languages
        $languages = ['en', 'es', 'gb', 'fr', 'de', 'it', 'pt', 'ru', 'zh', 'ja', 'ar', 'hi', 'bn', 'pa', 'te', 'mr', 'ta', 'ur', 'gu', 'kn', 'ml', 'or', 'si', 'dv', 'ne', 'ps', 'sd', 'ku', 'fa', 'pa', 'gu', 'bn', 'or', 'ta', 'te', 'kn', 'ml', 'si', 'th', 'lo', 'my', 'ka', 'am', 'ti', 'bo', 'km', 'lo', 'vi'];
        return [
            'name' => fake()->name(),
            'title' => fake()->name(),
            'order' => 0,
            'code' => $languages[array_rand($languages)],
        ];
    }
}
