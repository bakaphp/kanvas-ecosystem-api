<?php

declare(strict_types=1);

namespace Kanvas\Social\MessagesTypes\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Languages\Models\Languages;
use Kanvas\Social\MessagesTypes\Models\MessageType;

class MessageTypeFactory extends Factory
{
    protected $model = MessageType::class;

    public function definition()
    {
        $languages = Languages::factory()->create();

        return [
            'name' => fake()->name,
            'apps_id' => 1,
            'languages_id' => $languages->id,
            'verb' => 'create',
            'template' => '<fake>',
            'templates_plura' => '<fake>',
            'message_schema' => json_encode([
                'required' => ['name', 'email'],
                'optional' => ['phone'],
                'types' => [
                    'name' => 'string',
                    'email' => 'string',
                    'phone' => 'string'
                ]
            ])
        ];
    }
}
