<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Workflows\HumanAgentChannelResponseActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

class HumanAgentChannelResponseActivityVerbTest extends TestCase
{
    use DatabaseTransactions;
    use HasIntegrationCompany;

    public function testHumanReplyGetsItsMessageTypeVerbMirroredIntoThePayload(): void
    {
        [$app, $channel, $message] = $this->makeChannelMessage(
            verb: 'sms',
            payload: ['content' => 'Hi from a human', 'from_me' => true, 'from_human' => true],
        );

        $this->assertArrayNotHasKey('verb', $message->message);

        $result = $this->runActivity($channel, $app, $message);

        $this->assertStringContainsString('From phone number is required', $result['message'] ?? '');

        $stored = $message->refresh()->message;
        $this->assertSame('sms', $stored['verb']);
        $this->assertSame($message->messageType->verb, $stored['verb']);
        $this->assertSame('Hi from a human', $stored['content']);
        $this->assertTrue($stored['from_human']);
    }

    public function testNoteGetsItsMessageTypeVerbMirroredIntoThePayload(): void
    {
        [$app, $channel, $message] = $this->makeChannelMessage(
            verb: 'note',
            payload: ['content' => 'Internal note', 'from_me' => true],
        );

        $result = $this->runActivity($channel, $app, $message);

        $this->assertStringContainsString('not from human agent', $result['message'] ?? '');
        $this->assertSame('note', $message->refresh()->message['verb']);
    }

    public function testExistingVerbIsLeftAlone(): void
    {
        [$app, $channel, $message] = $this->makeChannelMessage(
            verb: 'sms',
            payload: ['content' => 'Hi', 'from_me' => true, 'from_human' => true, 'verb' => 'sms'],
        );

        $updatedAt = $message->updated_at;

        $this->runActivity($channel, $app, $message);

        $this->assertSame('sms', $message->refresh()->message['verb']);
        $this->assertEquals($updatedAt, $message->updated_at);
    }

    private function runActivity(Channel $channel, Apps $app, Message $message): array
    {
        return new HumanAgentChannelResponseActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), [])->execute(
            $channel,
            $app,
            ['message' => $message],
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: Apps, 1: Channel, 2: Message}
     */
    private function makeChannelMessage(string $verb, array $payload): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->setIntegration($app, IntegrationsEnum::INTERNAL, 'internal', $company, $user);

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();

        $channel = new CreateChannelAction(
            ChannelDto::from([
                'apps' => $app,
                'companies' => $company,
                'users' => $lead->user,
                'entity_id' => $lead->getId(),
                'entity_namespace' => Lead::class,
                'name' => 'Human reply — ' . $lead->getId(),
                'slug' => 'human-reply-' . $lead->getId(),
            ])
        )->execute();

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => $verb],
            ['name' => ucfirst($verb)]
        );

        $create = new CreateMessageAction(new MessageInput(
            app: $app,
            company: $company,
            user: $user,
            type: $messageType,
            message: $payload,
            is_public: 1,
        ));
        $create->runWorkflow = false;
        $message = $create->execute();

        $message->addEntity($lead);
        $channel->addMessage($message);

        return [$app, $channel, $message];
    }
}
