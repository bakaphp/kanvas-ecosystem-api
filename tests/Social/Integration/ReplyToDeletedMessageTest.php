<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class ReplyToDeletedMessageTest extends TestCase
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

    public function testReplyingToASoftDeletedParentStaysInItsThread(): void
    {
        $type = $this->makeMessageType();
        $parent = $this->makeMessage($type);
        $parent->delete();

        $reply = $this->makeMessage($type, $parent->getId());

        $this->assertSame($parent->getId(), (int) $reply->parent_id);
        $this->assertStringStartsWith((string) $parent->getPath(), (string) $reply->fresh()->getPath());
        $this->assertFalse($reply->relationLoaded('parent'));
    }

    public function testReplyingToALiveParentStillBuildsThePath(): void
    {
        $type = $this->makeMessageType();
        $parent = $this->makeMessage($type);

        $reply = $this->makeMessage($type, $parent->getId());

        $this->assertSame($parent->getId(), (int) $reply->parent_id);
        $this->assertStringStartsWith((string) $parent->getPath(), (string) $reply->fresh()->getPath());
    }

    private function makeMessageType(): MessageType
    {
        return new CreateMessageTypeAction(
            new MessageTypeInput(
                apps_id: $this->currentApp->getId(),
                languages_id: 1,
                name: 'reply-deleted-test-' . uniqid(),
                verb: 'reply-deleted-test-' . uniqid(),
            )
        )->execute();
    }

    private function makeMessage(MessageType $type, ?int $parentId = null): Message
    {
        $action = new CreateMessageAction(
            new MessageInput(
                app: $this->currentApp,
                company: $this->currentCompany,
                user: $this->actingUser,
                type: $type,
                message: ['content' => 'hello thread'],
                parent_id: $parentId,
                is_public: 1,
            )
        );
        $action->runWorkflow = false;

        return $action->execute();
    }
}
