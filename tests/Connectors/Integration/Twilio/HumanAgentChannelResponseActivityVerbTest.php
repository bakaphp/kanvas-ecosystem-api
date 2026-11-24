<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Workflows\HumanAgentChannelResponseActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\SmsOptOutNoticeService;
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

/**
 * The activity must never write the message-type verb into a human reply or note:
 * the frontend hides user-authored messages that carry a `verb` key.
 */
class HumanAgentChannelResponseActivityVerbTest extends TestCase
{
    use DatabaseTransactions;
    use HasIntegrationCompany;

    private const string FROM_PHONE = '+15005550006';

    public function testHumanReplyPayloadIsNotTaggedWithTheMessageTypeVerb(): void
    {
        $payload = ['content' => 'Hi from a human', 'from_me' => true, 'from_human' => true];

        [$app, $channel, $message] = $this->makeChannelMessage(verb: 'sms', payload: $payload);

        $result = $this->runActivity($channel, $app, $message);

        $this->assertStringContainsString('From phone number is required', $result['message'] ?? '');

        $stored = $message->refresh()->message;
        $this->assertArrayNotHasKey('verb', $stored);
        $this->assertSame($payload, $stored);
    }

    public function testNotePayloadIsNotTaggedWithTheMessageTypeVerb(): void
    {
        $payload = ['content' => 'Internal note', 'from_me' => true];

        [$app, $channel, $message] = $this->makeChannelMessage(verb: 'note', payload: $payload);

        $result = $this->runActivity($channel, $app, $message);

        $this->assertStringContainsString('not from human agent', $result['message'] ?? '');

        $stored = $message->refresh()->message;
        $this->assertArrayNotHasKey('verb', $stored);
        $this->assertSame($payload, $stored);
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

    /**
     * The thread and a resend both read the stored row, so the clause the customer received has
     * to be on it and not only on the text handed to the provider.
     */
    public function testFirstHumanSmsStoresTheOptOutNotice(): void
    {
        $payload = ['content' => 'Hi from a human', 'from_me' => true, 'from_human' => true];

        [$app, $channel, $message] = $this->makeChannelMessage(verb: 'sms', payload: $payload);

        $this->runActivity($channel, $app, $message, self::FROM_PHONE);

        $this->assertSame(
            ['content' => "Hi from a human\n\n" . SmsOptOutNoticeService::NOTICE] + $payload,
            $message->refresh()->message,
        );
    }

    public function testLaterHumanSmsIsStoredAsWritten(): void
    {
        $payload = ['content' => 'Second one', 'from_me' => true, 'from_human' => true];

        [$app, $channel, $message] = $this->makeChannelMessage(verb: 'sms', payload: $payload);
        $channel->addMessage($this->makeMessage($app, 'sms', ['content' => 'First one', 'from_me' => true]));

        $updatedAt = $message->updated_at;

        $this->runActivity($channel, $app, $message, self::FROM_PHONE);

        $this->assertSame($payload, $message->refresh()->message);
        $this->assertEquals($updatedAt, $message->updated_at);
    }

    public function testHumanSmsThatAlreadyCarriesANoticeIsStoredAsWritten(): void
    {
        $payload = ['content' => 'Hi. Reply STOP to opt out.', 'from_me' => true, 'from_human' => true];

        [$app, $channel, $message] = $this->makeChannelMessage(verb: 'sms', payload: $payload);

        $this->runActivity($channel, $app, $message, self::FROM_PHONE);

        $this->assertSame($payload, $message->refresh()->message);
    }

    private function runActivity(Channel $channel, Apps $app, Message $message, ?string $from = null): array
    {
        return new HumanAgentChannelResponseActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), [])->execute(
            $channel,
            $app,
            ['message' => $message] + ($from !== null ? ['from' => $from] : []),
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

        $message = $this->makeMessage($app, $verb, $payload);

        $message->addEntity($lead);
        $channel->addMessage($message);

        return [$app, $channel, $message];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function makeMessage(Apps $app, string $verb, array $payload): Message
    {
        $user = auth()->user();

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => $verb],
            ['name' => ucfirst($verb)]
        );

        $create = new CreateMessageAction(new MessageInput(
            app: $app,
            company: $user->getCurrentCompany(),
            user: $user,
            type: $messageType,
            message: $payload,
            is_public: 1,
        ));
        $create->runWorkflow = false;

        return $create->execute();
    }
}
