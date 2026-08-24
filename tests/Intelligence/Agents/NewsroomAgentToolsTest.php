<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Neuron\Tools\Social\CreateMessageTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Social\ReadChannelWindowTool;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class NewsroomAgentToolsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'social'];

    public function testReadsTheRecentWindowOfAChannelNewestFirst(): void
    {
        $channel = $this->channel();

        $first = $this->messageOn($channel, 'Anyone free Tuesday?');
        $second = $this->messageOn($channel, 'We just closed the Acme deal.');

        $result = $this->reader()->__invoke(channel_id: $channel->getId());

        $this->assertSame('success', $result['status']);
        $this->assertSame($channel->getId(), $result['channel_id']);
        $this->assertGreaterThanOrEqual(2, $result['returned']);

        $ids = array_column($result['messages'], 'message_id');
        $this->assertSame($second->getId(), $ids[0], 'The newest message must come first.');
        $this->assertContains($first->getId(), $ids);

        $this->assertSame('We just closed the Acme deal.', $result['messages'][0]['text']);
        $this->assertArrayHasKey('author', $result['messages'][0]);
    }

    public function testDefaultsToTheChannelOfTheRecordItWasWokenOn(): void
    {
        $channel = $this->channel();
        $message = $this->messageOn($channel, 'Something worth writing up.');

        $result = $this->reader()->withEntity($message)->__invoke();

        $this->assertSame('success', $result['status']);
        $this->assertSame($channel->getId(), $result['channel_id']);
    }

    public function testWithNoChannelAndNoRecordItSaysSoRatherThanGuessing(): void
    {
        $result = $this->reader()->__invoke();

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('channel_id', $result['message']);
    }

    public function testAChannelInAnotherCompanyIsNotReadable(): void
    {
        $channel = $this->channel();
        $channel->companies_id = 999999;
        $channel->saveQuietly();

        $result = $this->reader()->__invoke(channel_id: $channel->getId());

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('does not exist', $result['message']);
    }

    public function testWorkProductIsSavedAsTheRequestedTypeWithNamedFields(): void
    {
        $type = $this->articleType();

        $result = $this->writer()->__invoke(
            content: '{"title": "Acme deal closed", "content": "<p>Body.</p>", "categories": ["News"]}',
            verb: $type->verb,
        );

        $this->assertSame('success', $result['status'], json_encode($result));

        /** @var Message $message */
        $message = Message::query()->whereKey($result['message_id'])->first();

        $this->assertNotNull($message);
        $this->assertSame($type->getId(), (int) $message->message_types_id);
        $this->assertSame('Acme deal closed', $message->getMessage()['title']);
        $this->assertSame(['News'], $message->getMessage()['categories']);
    }

    /**
     * Every other message-creation path in Kanvas dispatches the message-created workflow; the rules
     * and the integration decide whether anything actually runs. This tool must not be the exception.
     */
    public function testItDoesNotSuppressTheMessageWorkflow(): void
    {
        $source = file_get_contents(
            base_path('src/Domains/Intelligence/Agents/Neuron/Tools/Social/CreateMessageTool.php')
        );

        $this->assertStringNotContainsString('runWorkflow = false', $source);
    }

    public function testTheTypeIsCreatedOnDemandWhenItIsNew(): void
    {
        $verb = 'agent-chat-' . fake()->unique()->lexify('?????');

        $result = $this->writer()->__invoke(content: 'Just talking.', verb: $verb);

        $this->assertSame('success', $result['status'], json_encode($result));
        $this->assertSame($verb, $result['message_type']);
    }

    private function reader(): ReadChannelWindowTool
    {
        return new ReadChannelWindowTool()->withContext($this->kanvasApp(), $this->company(), $this->currentUser());
    }

    private function writer(): CreateMessageTool
    {
        return new CreateMessageTool($this->kanvasApp(), $this->company(), $this->currentUser());
    }

    private function channel(): Channel
    {
        $slug = 'wa-group-' . fake()->unique()->uuid();

        return Channel::create([
            'name' => $slug,
            'slug' => $slug,
            'description' => 'WhatsApp group channel used in tests',
            'apps_id' => $this->kanvasApp()->getId(),
            'companies_id' => $this->company()->getId(),
            'users_id' => $this->currentUser()->getId(),
        ]);
    }

    private function messageOn(Channel $channel, string $text): Message
    {
        $action = new CreateMessageAction(new MessageInput(
            app: $this->kanvasApp(),
            company: $this->company(),
            user: $this->currentUser(),
            type: $this->chatType(),
            message: ['content' => $text],
        ));
        $action->runWorkflow = false;
        $message = $action->execute();

        $channel->addMessage($message, $this->currentUser());

        return $message;
    }

    private function articleType(): MessageType
    {
        return MessageTypeService::getOrCreate(app: $this->kanvasApp(), verb: 'news-article');
    }

    private function chatType(): MessageType
    {
        return MessageTypeService::getOrCreate(app: $this->kanvasApp(), verb: 'group-chat');
    }

    private function kanvasApp(): Apps
    {
        return app(Apps::class);
    }

    private function company(): Companies
    {
        return $this->currentUser()->getCurrentCompany();
    }

    private function currentUser(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }
}
