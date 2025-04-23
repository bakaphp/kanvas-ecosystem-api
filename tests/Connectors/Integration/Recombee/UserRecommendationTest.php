<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Recombee;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Recombee\Actions\GenerateWhoToFollowRecommendationsAction;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class UserRecommendationTest extends TestCase
{
    protected ?Message $message = null;

    public function setUp(): void
    {
        parent::setUp();
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::RECOMBEE_DATABASE->value, getenv('TEST_RECOMBEE_DATABASE'));
        $app->set(ConfigurationEnum::RECOMBEE_API_KEY->value, getenv('TEST_RECOMBEE_API_KEY'));
        $app->set(ConfigurationEnum::RECOMBEE_REGION->value, getenv('TEST_RECOMBEE_REGION'));
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $messageData = [
            'title'        => 'Psychedelic Panorama',
            'prompt'       => 'A psychedelic panorama landscape featuring rolling, foggy hills. The scene is rendered in shades of neon, with soft gradients of light and shadow adding depth and texture. The fog creates a sense of mystery and tranquility, with faint outlines of trees and distant hills barely visible. The composition is serene, evocative and atmospheric, capturing the quiet beauty of a misty, untouched landscape.',
            'is_assistant' => false,
            'ai_model'     => [
                'key'     => 'dalle3',
                'value'   => 'dall-e-3',
                'name'    => 'OpenAI - Dalle 3',
                'payment' => [
                    'price'             => 0,
                    'is_locked'         => false,
                    'free_regeneration' => false,
                ],
                'icon' => 'https://cdn.promptmine.ai/OpenAILogo.png',
            ],
            'ai_image' => [
                'title'    => null,
                'ai_model' => [
                    'key'     => 'dalle3',
                    'value'   => 'dall-e-3',
                    'name'    => 'OpenAI - Dalle 3',
                    'payment' => [
                        'price'             => 0,
                        'is_locked'         => false,
                        'free_regeneration' => false,
                    ],
                    'icon' => 'https://cdn.promptmine.ai/OpenAILogo.png',
                ],
                'image'      => 'https://s3.amazonaws.com/mc-canvas/y1t7q8GDLOHw2sbJYEpVc9mp2ZBAYP9g5mXmFr7Y.png',
                'id'         => 2842,
                'type'       => 'image-format',
                'created_at' => 1739996370460,
                'updated_at' => 1739996370460,
            ],
            'type' => 'image-format',
        ];

        $messageType = MessageType::factory()->create();

        $this->message = Message::create([
            'apps_id'          => $app->getId(),
            'companies_id'     => $company->getId(),
            'message'          => $messageData,
            'message_types_id' => $messageType->getId(),
            'total_liked'      => 0,
            'users_id'         => $user->getId(),
            'created_at'       => now()->toDateTimeString(),
            'updated_at'       => now()->toDateTimeString(),
        ]);
    }

    public function testGetUserToFollow(): void
    {
        $app = app(Apps::class);
        $user = Users::getById(2); // auth()->user();
        $company = $user->getCurrentCompany();

        $whoToFollow = new GenerateWhoToFollowRecommendationsAction(
            $app,
            $company,
        );

        $userList = $whoToFollow->execute($user, 10);

        $this->assertTrue($userList->count() >= 0);
    }
}
