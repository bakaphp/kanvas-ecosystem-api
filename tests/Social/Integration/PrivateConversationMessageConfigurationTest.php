<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Tests\TestCase;

class PrivateConversationMessageConfigurationTest extends TestCase
{
    public function testEnabledConfigurationMakesConversationMessagesPrivateAndLocked(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::PRIVATE_AND_LOCK_CONVERSATION_MESSAGES->value, true);

        try {
            $agentMessage = $this->createMessage($app, 'agent-message');
            $regularMessage = $this->createMessage($app, 'message');
            $note = $this->createMessage($app, 'note');

            $this->assertSame(0, (int) $agentMessage->is_public);
            $this->assertSame(1, (int) $agentMessage->is_locked);
            $this->assertSame(0, (int) $regularMessage->is_public);
            $this->assertSame(1, (int) $regularMessage->is_locked);
            $this->assertSame(1, (int) $note->is_public);
            $this->assertSame(0, (int) $note->is_locked);
        } finally {
            $app->del(ConfigurationEnum::PRIVATE_AND_LOCK_CONVERSATION_MESSAGES->value);
        }
    }

    public function testDisabledConfigurationPreservesAgentMessageVisibility(): void
    {
        $app = app(Apps::class);
        $app->del(ConfigurationEnum::PRIVATE_AND_LOCK_CONVERSATION_MESSAGES->value);

        $message = $this->createMessage($app, 'agent-message');

        $this->assertSame(1, (int) $message->is_public);
        $this->assertSame(0, (int) $message->is_locked);
    }

    private function createMessage(Apps $app, string $verb): Message
    {
        $user = auth()->user();
        $action = new CreateMessageAction(new MessageInput(
            app: $app,
            company: $user->getCurrentCompany(),
            user: $user,
            type: MessageTypeService::getOrCreate($app, $verb),
            message: ['content' => 'Test message'],
        ));
        $action->runWorkflow = false;

        return $action->execute();
    }
}
