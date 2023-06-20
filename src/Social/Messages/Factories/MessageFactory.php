<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Social\Messages\Models\Message;

class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition()
    {
        return [
            'parent_id' => null,
            'parent_unique_id' => null,
            'apps_id' => 1,
            'companies_id' => 1,
            'users_id' => 1,
            'message_types_id' => 1,
            'message' => [
                'message' => $this->faker->text,
                'params' => [],
            ],
        ];
    }
}
