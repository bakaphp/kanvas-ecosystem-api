<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Services\SmsOptOutNoticeService;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Enums\MessageSenderTypeEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\TestCase;

/**
 * The opt-out clause rides on the first SMS we send, whether a human or an agent wrote it and
 * whether we opened the conversation or the customer did. "First" is therefore counted over
 * outbound history (sender_type user/agent) — a prior inbound message must not consume it.
 */
class SmsOptOutNoticeServiceTest extends TestCase
{
    public function testEmptyChannelIsFirstOutboundMessage(): void
    {
        ['message' => $message, 'channel' => $channel] = $this->setupChannelAndMessage();

        $this->assertTrue(
            SmsOptOutNoticeService::isFirstOutboundMessage($channel, $message),
            'a channel with no attached messages should be treated as the first outbound'
        );
    }

    public function testChannelWithOnlyCurrentMessageIsFirstOutboundMessage(): void
    {
        ['message' => $message, 'channel' => $channel] = $this->setupChannelAndMessage();

        $this->attachMessage($channel, $message);

        $this->assertTrue(
            SmsOptOutNoticeService::isFirstOutboundMessage($channel, $message),
            'the message being sent must be excluded when deciding if it is the first outbound'
        );
    }

    public function testInboundCustomerMessageDoesNotConsumeTheFirstOutbound(): void
    {
        ['message' => $message, 'channel' => $channel, 'messageType' => $messageType] = $this->setupChannelAndMessage();

        $inbound = $this->makeMessage($messageType, 'hi, is the CR-V still available?', direction: 'inbound');
        $this->attachMessage($channel, $inbound);
        $this->attachMessage($channel, $message);

        $this->assertSame(MessageSenderTypeEnum::CONTACT->value, $inbound->refresh()->sender_type);
        $this->assertTrue(
            SmsOptOutNoticeService::isFirstOutboundMessage($channel, $message),
            'a customer-opened conversation must still get the clause on our first reply'
        );
    }

    public function testChannelWithPriorOutboundIsNotFirstOutboundMessage(): void
    {
        ['message' => $message, 'channel' => $channel, 'messageType' => $messageType] = $this->setupChannelAndMessage();

        $priorMessage = $this->makeMessage($messageType, 'earlier outbound', direction: 'agent');
        $this->attachMessage($channel, $priorMessage);
        $this->attachMessage($channel, $message);

        $this->assertSame(MessageSenderTypeEnum::AGENT->value, $priorMessage->refresh()->sender_type);
        $this->assertFalse(
            SmsOptOutNoticeService::isFirstOutboundMessage($channel, $message),
            'a channel that already sent an outbound message is not the first outbound'
        );
    }

    public function testReplyLaneWithNoPersistedOutboundYetIsFirstOutboundMessage(): void
    {
        ['channel' => $channel, 'messageType' => $messageType] = $this->setupChannelAndMessage();

        $inbound = $this->makeMessage($messageType, 'inbound that triggered the reply', direction: 'inbound');
        $this->attachMessage($channel, $inbound);

        $this->assertTrue(
            SmsOptOutNoticeService::isFirstOutboundMessage($channel),
            'the reply lane has no outbound row yet and passes no exclusion'
        );
    }

    public function testSoftDeletedPriorOutboundStillCountsAsFirstOutboundMessage(): void
    {
        ['message' => $message, 'channel' => $channel, 'messageType' => $messageType] = $this->setupChannelAndMessage();

        $deletedMessage = $this->makeMessage($messageType, 'deleted outbound', direction: 'agent', isDeleted: true);
        $this->attachMessage($channel, $deletedMessage);
        $this->attachMessage($channel, $message);

        $this->assertTrue(
            SmsOptOutNoticeService::isFirstOutboundMessage($channel, $message),
            'soft-deleted messages must not count toward prior outbound history'
        );
    }

    public function testBodyWithExistingOptOutLanguageIsDetected(): void
    {
        $this->assertTrue(SmsOptOutNoticeService::hasNotice('Hey there. Reply STOP to opt out.'));
        $this->assertTrue(SmsOptOutNoticeService::hasNotice('text STOP to opt out anytime'));
        $this->assertTrue(SmsOptOutNoticeService::hasNotice('you may opt-out later'));
    }

    public function testBodyWithoutOptOutLanguageIsNotDetected(): void
    {
        $this->assertFalse(SmsOptOutNoticeService::hasNotice('Following up on your vehicle, are you still interested?'));
        $this->assertFalse(SmsOptOutNoticeService::hasNotice("we won't stop until we find you a deal"));
    }

    public function testAppendsNoticeToBodyWithoutOne(): void
    {
        $this->assertSame(
            "Hi Ana, are you still looking at the CR-V?\n\n" . SmsOptOutNoticeService::NOTICE,
            SmsOptOutNoticeService::appendTo('  Hi Ana, are you still looking at the CR-V?  ')
        );
    }

    public function testDoesNotDoubleAppendWhenAgentAlreadyWroteIt(): void
    {
        $body = 'Hi Ana, are you still looking at the CR-V? Reply STOP to opt out.';

        $this->assertSame($body, SmsOptOutNoticeService::appendTo($body));
    }

    public function testAppendingToAnEmptyBodyYieldsJustTheNotice(): void
    {
        $this->assertSame(SmsOptOutNoticeService::NOTICE, SmsOptOutNoticeService::appendTo('   '));
    }

    private function attachMessage(Channel $channel, Message $message): void
    {
        $channel->messages()->attach($message->getId(), ['users_id' => auth()->user()->getId()]);
    }

    /**
     * `direction` drives the payload flags the MessageObserver classifies into sender_type:
     * user = human outbound, agent = AI outbound, inbound = the customer.
     */
    private function makeMessage(
        MessageType $messageType,
        string $content,
        string $direction = 'user',
        bool $isDeleted = false
    ): Message {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $payload = match ($direction) {
            'agent' => ['from_me' => true, 'from_ia' => true],
            'inbound' => ['from_me' => false, 'from_ia' => false],
            default => ['from_me' => true, 'from_ia' => false],
        };

        return Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create([
                'message' => ['content' => $content] + $payload,
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

        $message = $this->makeMessage($messageType, 'first outbound message');

        $channel = Channel::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'slug' => 'sms-optout-' . $message->getId(),
            ],
            [
                'name' => 'Test SMS Channel',
                'description' => 'Test channel for SMS opt-out logic',
                'users_id' => $user->getId(),
            ]
        );

        return compact('message', 'channel', 'messageType');
    }
}
