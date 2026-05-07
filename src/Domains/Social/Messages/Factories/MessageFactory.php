<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Override;

class MessageFactory extends Factory
{
    protected $model = Message::class;

    #[Override]
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'parent_unique_id' => null,
            'apps_id' => function (array $attributes) {
                return app(Apps::class)->getId();
            },
            'companies_id' => function (array $attributes) {
                return auth()->user()->getCurrentCompany()->getId();
            },
            'users_id' => function (array $attributes) {
                return auth()->user()->getId();
            },
            'message_types_id' => 1,
            'message' => [
                'message' => $this->faker->text,
                'params' => [],
            ],
        ];
    }

    public function withAppId(int $appId): static
    {
        return $this->state(function (array $attributes) use ($appId) {
            return [
                'apps_id' => $appId,
            ];
        });
    }

    public function withCompanyId(int $companyId): static
    {
        return $this->state(function (array $attributes) use ($companyId) {
            return [
                'companies_id' => $companyId,
            ];
        });
    }

    public function withMessageTypeId(int $messageTypeId): static
    {
        return $this->state(function (array $attributes) use ($messageTypeId) {
            return [
                'message_types_id' => $messageTypeId,
            ];
        });
    }

    public function withMessageType(MessageType $messageType): static
    {
        return $this->state(function (array $attributes) use ($messageType) {
            return [
                'message_types_id' => $messageType->getId(),
            ];
        });
    }
}
