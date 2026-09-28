<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class DeleteChannelMessageTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'social'];

    private Apps $currentApp;
    private Companies $currentCompany;
    private Users $actingUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->actingUser = auth()->user();
        $this->currentCompany = $this->actingUser->getCurrentCompany();
    }

    public function testDeletingTheOnlyMessageKeepsTheChannelAlive(): void
    {
        $channel = $this->makeChannel();
        $message = $this->makeMessage($this->makeMessageType());

        $channel->addMessage($message, $this->actingUser);

        $message->delete();

        $channel->refresh();

        $this->assertEquals(0, $channel->is_deleted);
        $this->assertNull($channel->last_message_id);
        $this->assertNotNull(Channel::find($channel->id));
    }

    public function testDeletingTheLastMessageMovesThePointerToThePreviousOne(): void
    {
        $type = $this->makeMessageType();
        $channel = $this->makeChannel();

        $first = $this->makeMessage($type);
        $channel->addMessage($first, $this->actingUser);

        $second = $this->makeMessage($type);
        $channel->addMessage($second, $this->actingUser);

        $second->delete();

        $channel->refresh();

        $this->assertEquals(0, $channel->is_deleted);
        $this->assertEquals($first->id, $channel->last_message_id);
    }

    public function testDeletingANonLastMessageLeavesThePointerUntouched(): void
    {
        $type = $this->makeMessageType();
        $channel = $this->makeChannel();

        $first = $this->makeMessage($type);
        $channel->addMessage($first, $this->actingUser);

        $second = $this->makeMessage($type);
        $channel->addMessage($second, $this->actingUser);

        $first->delete();

        $channel->refresh();

        $this->assertEquals(0, $channel->is_deleted);
        $this->assertEquals($second->id, $channel->last_message_id);
    }

    private function makeChannel(): Channel
    {
        return Channel::create([
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $this->currentCompany->getId(),
            'users_id' => $this->actingUser->getId(),
            'name' => 'Delete message channel ' . uniqid(),
            'description' => 'Delete message channel',
            'slug' => 'delete-message-channel-' . uniqid(),
        ]);
    }

    private function makeMessageType(): MessageType
    {
        return new CreateMessageTypeAction(
            new MessageTypeInput(
                apps_id: $this->currentApp->getId(),
                languages_id: 1,
                name: 'delete-message-test-' . uniqid(),
                verb: 'delete-message-test-' . uniqid(),
            )
        )->execute();
    }

    private function makeMessage(MessageType $type): Message
    {
        $action = new CreateMessageAction(
            new MessageInput(
                app: $this->currentApp,
                company: $this->currentCompany,
                user: $this->actingUser,
                type: $type,
                message: ['content' => 'hello channel'],
                is_public: 1,
            )
        );
        $action->runWorkflow = false;

        return $action->execute();
    }
}
