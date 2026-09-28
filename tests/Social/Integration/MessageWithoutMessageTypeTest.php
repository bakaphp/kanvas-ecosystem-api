<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\TestCase;

/**
 * `messages.message_types_id` carries no foreign key, so a message can outlive its type row and
 * every observer hook has to survive a null `messageType` — a fatal there kills the write itself.
 */
class MessageWithoutMessageTypeTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social'];

    public function testTheFullLifecycleSurvivesAMissingMessageType(): void
    {
        $message = $this->createMessageWithoutType();

        $this->assertNull($message->messageType);

        $message->message = ['content' => 'edited', 'from_me' => true];
        $message->saveOrFail();

        $message->delete();

        $this->assertTrue($message->refresh()->isDeleted());
    }

    public function testAMissingMessageTypeIsNeverTreatedAsTheIndexedType(): void
    {
        $app = app(Apps::class);
        $app->set('index_message_by_type', 'sms');

        try {
            $this->assertFalse($this->createMessageWithoutType()->isIndexedMessageType());
        } finally {
            $app->del('index_message_by_type');
        }
    }

    public function testAMessageIsNotIndexedWhenTheAppDeclaresNoIndexedType(): void
    {
        $type = MessageType::factory()->create([
            'apps_id' => app(Apps::class)->getId(),
            'verb' => 'sms-' . fake()->unique()->lexify('????'),
        ]);

        $message = Message::factory()->create([
            'message_types_id' => $type->getId(),
            'message' => ['content' => 'hi', 'from_me' => true],
        ]);

        $this->assertFalse($message->isIndexedMessageType());
    }

    public function testAMessageIsIndexedWhenItsVerbMatchesTheAppsIndexedType(): void
    {
        $app = app(Apps::class);
        $verb = 'sms-' . fake()->unique()->lexify('????');

        $type = MessageType::factory()->create([
            'apps_id' => $app->getId(),
            'verb' => $verb,
        ]);

        $app->set('index_message_by_type', $verb);

        try {
            $message = Message::factory()->create([
                'message_types_id' => $type->getId(),
                'message' => ['content' => 'hi', 'from_me' => true],
            ]);

            $this->assertTrue($message->isIndexedMessageType());
        } finally {
            $app->del('index_message_by_type');
        }
    }

    private function createMessageWithoutType(): Message
    {
        return Message::factory()->create([
            'message_types_id' => (int) MessageType::query()->max('id') + 1000,
            'message' => ['content' => 'hi', 'from_me' => true],
        ]);
    }
}
