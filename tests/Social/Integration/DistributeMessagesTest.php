<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\Actions\DistributeMessagesToUsersAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\UserMessage;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Tests\TestCase;

class DistributeMessagesTest extends TestCase
{
    public function testExecute(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $data = [
            'description' => 'As the 2024',
            'title' => 'What You Need to Know',
            'display_type' => [
                'id' => '1',
                'name' => 'bullet',
            ],
            'parts' => [
                [
                    'id' => 6934,
                    'title' => 'What You Need to Know',
                ],
            ],
            'type' => [
                'id' => '1',
                'name' => 'Single',
            ],
            'created_at' => 1729675973544,
            'updated_at' => 1729675973544,
        ];

        $createMessage = new CreateMessageAction(
            new MessageInput(
                $app,
                $user->getCurrentCompany(),
                $user,
                (new CreateMessageTypeAction(
                    new MessageTypeInput(
                        $app->getId(),
                        1,
                        'create',
                        'Create',
                        'Create',
                    )
                ))->execute(),
                $data,
            )
        );

        $totalBefore = UserMessage::fromApp($app)->count();
        $message = $createMessage->execute();
        $distributeMessagesToUsers = (new DistributeMessagesToUsersAction($message, $app))->execute();

        $totalAfter = UserMessage::fromApp($app)->count();
        $this->assertTrue($distributeMessagesToUsers);
        $this->assertEquals($totalBefore + 1, $totalAfter);
    }
}
