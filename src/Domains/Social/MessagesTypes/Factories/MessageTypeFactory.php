<?php

declare(strict_types=1);

namespace Kanvas\Social\MessagesTypes\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Languages\Models\Languages;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Override;

class MessageTypeFactory extends Factory
{
    protected $model = MessageType::class;

    #[Override]
    public function definition(): array
    {
        $languages = Languages::factory()->create();

        return [
            'name' => fake()->name,
            'apps_id' => function (array $attributes) {
                return app(Apps::class)->getId();
            },
            'languages_id' => $languages->id,
            'verb' => fake()->unique()->lexify('verb-????'),
            'template' => '<fake>',
            'templates_plura' => '<fake>',
            'message_schema' => json_encode([
                'required' => ['name', 'email'],
                'optional' => ['phone'],
                'types' => [
                    'name' => 'string',
                    'email' => 'string',
                    'phone' => 'string',
                ],
            ]),
        ];
    }
}
