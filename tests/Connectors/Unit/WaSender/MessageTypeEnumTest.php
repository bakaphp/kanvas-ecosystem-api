<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\WaSender;

use Kanvas\Connectors\WaSender\Enums\MessageTypeEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MessageTypeEnumTest extends TestCase
{
    public function testContentKeySkipsMetadataSiblings(): void
    {
        $content = [
            'messageContextInfo' => ['messageSecret' => 'x'],
            'extendedTextMessage' => ['text' => 'hola'],
        ];

        $this->assertSame('extendedTextMessage', MessageTypeEnum::contentKey($content));
    }

    /**
     * Group payloads put messageContextInfo first often enough that array_key_first picked it and
     * the download read a mediaKey off the wrong node.
     */
    public function testMediaKeyIsFoundRegardlessOfNodeOrder(): void
    {
        $content = [
            'messageContextInfo' => ['messageSecret' => 'x'],
            'senderKeyDistributionMessage' => ['groupId' => 'y'],
            'imageMessage' => [
                'mediaKey' => 'k',
                'mimetype' => 'image/jpeg',
            ],
        ];

        $this->assertSame('imageMessage', MessageTypeEnum::mediaKey($content));
        $this->assertSame(MessageTypeEnum::IMAGE, MessageTypeEnum::getMessageType($content));
    }

    public function testTextMessageCarriesNoMediaKey(): void
    {
        $this->assertNull(MessageTypeEnum::mediaKey(['conversation' => 'hola']));
    }

    public function testExtendedTextMessageTypesAsText(): void
    {
        $this->assertSame(
            MessageTypeEnum::TEXT,
            MessageTypeEnum::getMessageType(['extendedTextMessage' => ['text' => 'hola']])
        );
    }

    #[DataProvider('wrappedMediaProvider')]
    public function testWrappersAreUnwrappedToTheRealContent(string $wrapper): void
    {
        $content = [
            $wrapper => [
                'message' => [
                    'imageMessage' => [
                        'mediaKey' => 'k',
                        'caption' => 'look',
                    ],
                ],
            ],
        ];

        $this->assertSame(MessageTypeEnum::IMAGE, MessageTypeEnum::getMessageType($content));
        $this->assertSame('imageMessage', MessageTypeEnum::mediaKey($content));
        $this->assertSame('look', MessageTypeEnum::extractText($content));
    }

    public static function wrappedMediaProvider(): array
    {
        return [
            'view once' => ['viewOnceMessageV2'],
            'ephemeral' => ['ephemeralMessage'],
            'document with caption' => ['documentWithCaptionMessage'],
        ];
    }

    /**
     * A forwarded ad card arrives as an extendedTextMessage carrying only mediaKey + contextInfo.
     * Real capture: a forwarded ad card.
     */
    public function testForwardedAdCardHasNoText(): void
    {
        $content = [
            'messageContextInfo' => ['messageSecret' => 'x'],
            'extendedTextMessage' => [
                'mediaKey' => 'ZXhhbXBsZS1tZWRpYS1rZXktZm9yLXRlc3RzLW9ubHk0',
                'contextInfo' => [
                    'isForwarded' => true,
                    'conversionSource' => 'FB_Post',
                ],
            ],
        ];

        $this->assertNull(MessageTypeEnum::extractText($content));
        $this->assertSame(MessageTypeEnum::TEXT, MessageTypeEnum::getMessageType($content));
    }

    public function testWhitespaceOnlyTextIsTreatedAsEmpty(): void
    {
        $this->assertNull(MessageTypeEnum::extractText(['conversation' => "  \n "]));
    }

    public function testUnwrapIsDepthBoundedOnSelfNestedPayloads(): void
    {
        $content = ['ephemeralMessage' => []];
        $content['ephemeralMessage']['message'] = $content;

        $this->assertSame(MessageTypeEnum::UNKNOWN, MessageTypeEnum::getMessageType($content));
    }
}
