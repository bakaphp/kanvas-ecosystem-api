<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Workflows\HumanAgentChannelResponseActivity;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The human-agent SMS path appends "Reply STOP to opt out." only on the first
 * message of a channel. "First" means the channel carries no other live message
 * besides the one we're about to send — mirrors what Sally does via her prompt.
 */
class HumanAgentChannelResponseOptOutTest extends TestCase
{
    public function testEmptyChannelIsFirstMessage(): void
    {
        ['message' => $message, 'channel' => $channel] = $this->setupChannelAndMessage();

        $this->assertTrue(
            $this->invokeIsFirstChannelMessage($channel, $message),
            'a channel with no attached messages should be treated as the first message'
        );
    }

    public function testChannelWithOnlyCurrentMessageIsFirstMessage(): void
    {
        ['message' => $message, 'channel' => $channel] = $this->setupChannelAndMessage();

        $this->attachMessage($channel, $message);

        $this->assertTrue(
            $this->invokeIsFirstChannelMessage($channel, $message),
            'the message being sent must be excluded when deciding if it is the first message'
        );
    }

    public function testChannelWithPriorMessageIsNotFirstMessage(): void
    {
        ['message' => $message, 'channel' => $channel, 'messageType' => $messageType] = $this->setupChannelAndMessage();

        $priorMessage = $this->makeMessage($messageType, 'earlier message');
        $this->attachMessage($channel, $priorMessage);
        $this->attachMessage($channel, $message);

        $this->assertFalse(
            $this->invokeIsFirstChannelMessage($channel, $message),
            'a channel that already has a prior message is not the first message'
        );
    }

    public function testSoftDeletedPriorMessageStillCountsAsFirstMessage(): void
    {
        ['message' => $message, 'channel' => $channel, 'messageType' => $messageType] = $this->setupChannelAndMessage();

        $deletedMessage = $this->makeMessage($messageType, 'deleted message', isDeleted: true);
        $this->attachMessage($channel, $deletedMessage);
        $this->attachMessage($channel, $message);

        $this->assertTrue(
            $this->invokeIsFirstChannelMessage($channel, $message),
            'soft-deleted messages must not count toward prior channel history'
        );
    }

    private function invokeIsFirstChannelMessage(Channel $channel, Message $message): bool
    {
        $activity = new ReflectionClass(HumanAgentChannelResponseActivity::class)->newInstanceWithoutConstructor();

        return new ReflectionMethod($activity, 'isFirstChannelMessage')
            ->invoke($activity, $channel, $message);
    }

    private function attachMessage(Channel $channel, Message $message): void
    {
        $channel->messages()->attach($message->getId(), ['users_id' => auth()->user()->getId()]);
    }

    private function makeMessage(MessageType $messageType, string $content, bool $isDeleted = false): Message
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create([
                'message' => ['content' => $content],
                'is_locked' => 0,
                'is_un_response' => 0,
                'is_deleted' => $isDeleted ? 1 : 0,
            ]);
    }

    private function setupChannelAndMessage(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'twilio-sms'],
            ['name' => 'Twilio SMS']
        );

        $message = $this->makeMessage($messageType, 'first human message');

        $channel = Channel::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'slug' => 'twilio-human-optout-' . $message->getId(),
            ],
            [
                'name' => 'Test Twilio Human Channel',
                'description' => 'Test channel for human opt-out logic',
                'users_id' => $user->getId(),
            ]
        );

        return compact('message', 'channel', 'messageType');
    }
}
