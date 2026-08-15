<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Internal\Handlers\InternalHandler;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Workflows\Activities\PrivatizeConversationMessageActivity;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

class PrivatizeConversationMessageActivityTest extends TestCase
{
    use HasIntegrationCompany;

    protected function setUp(): void
    {
        parent::setUp();

        // The activity runs through executeIntegration, which bails with an error envelope
        // (no `result` key) unless the company has the internal integration wired.
        $user = auth()->user();
        $this->setIntegration(
            app(Apps::class),
            IntegrationsEnum::INTERNAL,
            InternalHandler::class,
            $user->getCurrentCompany(),
            $user
        );
    }

    public function testItMakesMessageAndAgentMessagePrivateAndLocked(): void
    {
        $app = app(Apps::class);

        foreach (['message', 'agent-message'] as $verb) {
            $message = $this->makeMessage($app, $verb);
            $result = $this->activity()->execute($message, $app, []);
            $message->refresh();

            $this->assertTrue($result['result']);
            $this->assertSame(0, (int) $message->is_public);
            $this->assertSame(1, (int) $message->is_locked);
        }
    }

    public function testItSkipsOtherMessageTypes(): void
    {
        $app = app(Apps::class);
        $message = $this->makeMessage($app, 'note');

        $result = $this->activity()->execute($message, $app, []);
        $message->refresh();

        $this->assertFalse($result['result']);
        $this->assertSame(1, (int) $message->is_public);
        $this->assertSame(0, (int) $message->is_locked);
    }

    private function makeMessage(Apps $app, string $verb): Message
    {
        $user = auth()->user();

        return Message::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'message_types_id' => MessageTypeService::getOrCreate($app, $verb)->getId(),
            'is_public' => 1,
            'is_locked' => 0,
        ]);
    }

    private function activity(): PrivatizeConversationMessageActivity
    {
        return new PrivatizeConversationMessageActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            [],
        );
    }
}
