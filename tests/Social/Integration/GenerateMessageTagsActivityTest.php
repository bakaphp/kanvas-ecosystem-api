<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Workflows\Activities\GenerateMessageTagsActivity;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\TestCase;

class GenerateMessageTagsActivityTest extends TestCase
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
        $message = $createMessage->execute();

        $activity = new GenerateMessageTagsActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($message, $app, ['tags' => ['display_type.name','type.name','created_at']]);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('tags', $result);
        $this->assertArrayHasKey('display_type.name', $result['tags']);
    }
}
