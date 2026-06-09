<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use Kanvas\Social\Enums\ChannelCategoryEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCaseUnit;

class MessageCommunicationTypeTest extends TestCaseUnit
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function verbProvider(): array
    {
        return [
            'twilio sms' => ['twilio-sms', true],
            'mailgun email' => ['mailgun-email', true],
            'bare whatsapp' => ['whatsapp', true],
            'bare voice' => ['voice', true],
            'bare sms' => ['sms', true],
            'bare email' => ['email', true],
            'respondio text' => ['respondio-text', true],
            'respondio image variant' => ['respondio-image', true],
            'respondio audio variant' => ['respondio-audio', true],
            'internal notes' => ['internal_notes', false],
            'system notes' => ['system_notes', false],
            'system message' => ['system-message', false],
            'memo' => ['memo', false],
            'prompt' => ['prompt', false],
            'arbitrary verb' => ['verb-abcd', false],
        ];
    }

    #[DataProvider('verbProvider')]
    public function testIsCommunicationVerbMatchesChannelKeywords(string $verb, bool $expected): void
    {
        $this->assertSame(
            $expected,
            ChannelCategoryEnum::isCommunicationVerb($verb),
            "verb '{$verb}' communication classification mismatch",
        );
    }

    public function testIsCommunicationVerbIsFalseForNull(): void
    {
        $this->assertFalse(ChannelCategoryEnum::isCommunicationVerb(null));
    }

    public function testMessageDelegatesToChannelCategoryEnum(): void
    {
        $message = new Message();
        $message->setRelation('messageType', new MessageType(['verb' => 'twilio-sms']));

        $this->assertTrue($message->isCommunicationMessage());
    }

    public function testMessageIsNotCommunicationForInternalVerb(): void
    {
        $message = new Message();
        $message->setRelation('messageType', new MessageType(['verb' => 'internal_notes']));

        $this->assertFalse($message->isCommunicationMessage());
    }

    public function testMessageIsNotCommunicationWhenNoMessageType(): void
    {
        $message = new Message();
        $message->setRelation('messageType', null);

        $this->assertFalse($message->isCommunicationMessage());
    }
}
