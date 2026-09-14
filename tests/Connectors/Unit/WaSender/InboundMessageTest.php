<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\WaSender;

use Kanvas\Connectors\WaSender\DataTransferObject\InboundMessage;
use Kanvas\Connectors\WaSender\Enums\ConversationTypeEnum;
use Tests\TestCase;

/**
 * Payloads are trimmed copies of a real production capture (receiver 936, group
 * 15550001111-1700000000@g.us, a 90-second window) — the one behind Sentry
 * KANVAS-ECOSYSTEM-67S.
 */
final class InboundMessageTest extends TestCase
{
    public function testGroupMessageResolvesThreadAndSpeakerSeparately(): void
    {
        $inbound = InboundMessage::fromWebhookMessage($this->groupTextMessage());

        $this->assertNotNull($inbound);
        $this->assertSame('15550001111-1700000000@g.us', $inbound->conversationJid);
        $this->assertSame(ConversationTypeEnum::GROUP, $inbound->conversationType);
        $this->assertTrue($inbound->isGroup());

        $this->assertSame('15550001111', $inbound->senderPhone);
        $this->assertSame('900000000000001', $inbound->senderLid);
        $this->assertSame('15550001111@s.whatsapp.net', $inbound->senderJid);
        $this->assertSame('Alex Rivera', $inbound->pushName);
        $this->assertSame('3EBTEXT0000000000001', $inbound->messageId);
        $this->assertFalse($inbound->isFromMe);
    }

    public function testGroupSpeakerFallsBackToLidWhenPhoneIsUndisclosed(): void
    {
        $payload = $this->groupTextMessage();
        unset($payload['key']['participantPn'], $payload['key']['cleanedParticipantPn']);

        $inbound = InboundMessage::fromWebhookMessage($payload);

        $this->assertNotNull($inbound);
        $this->assertNull($inbound->senderPhone);
        $this->assertSame('900000000000001', $inbound->senderLid);
        $this->assertSame('900000000000001', $inbound->senderIdentity());
    }

    public function testAlbumPartsShareTheSameAlbumId(): void
    {
        $first = InboundMessage::fromWebhookMessage($this->groupAlbumImage('3AALBUM000000000001'));
        $second = InboundMessage::fromWebhookMessage($this->groupAlbumImage('3AALBUM000000000002'));

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame('3AALBUMPARENT000001', $first->albumId);
        $this->assertSame($first->albumId, $second->albumId);
        $this->assertNotSame($first->messageId, $second->messageId);
    }

    public function testMessageWithoutAlbumAssociationHasNoAlbumId(): void
    {
        $inbound = InboundMessage::fromWebhookMessage($this->groupTextMessage());

        $this->assertNotNull($inbound);
        $this->assertNull($inbound->albumId);
    }

    public function testLidAddressedDirectMessageUsesPhoneFormForTheThread(): void
    {
        $inbound = InboundMessage::fromWebhookMessage([
            'key' => [
                'id' => '3EB0F1',
                'fromMe' => false,
                'remoteJid' => '221234567890123@lid',
                'remoteJidAlt' => '15550003333@s.whatsapp.net',
                'addressingMode' => 'lid',
            ],
            'pushName' => 'Ana',
            'message' => ['conversation' => 'hola'],
        ]);

        $this->assertNotNull($inbound);
        $this->assertSame('15550003333@s.whatsapp.net', $inbound->conversationJid);
        $this->assertSame(ConversationTypeEnum::DIRECT, $inbound->conversationType);
        $this->assertSame('15550003333', $inbound->senderPhone);
        $this->assertSame('221234567890123', $inbound->senderLid);
    }

    public function testPhoneAddressedDirectMessageFallsBackToRemoteJid(): void
    {
        $inbound = InboundMessage::fromWebhookMessage([
            'key' => [
                'id' => '3EB0F2',
                'fromMe' => false,
                'remoteJid' => '15550003333@s.whatsapp.net',
            ],
            'message' => ['conversation' => 'hola'],
        ]);

        $this->assertNotNull($inbound);
        $this->assertSame('15550003333@s.whatsapp.net', $inbound->conversationJid);
        $this->assertSame('15550003333', $inbound->senderPhone);
        $this->assertNull($inbound->senderLid);
    }

    public function testUnroutableMessageResolvesToNullInsteadOfThrowing(): void
    {
        $this->assertNull(InboundMessage::fromWebhookMessage([
            'key' => [
                'id' => '3EB0F3',
                'fromMe' => false,
            ],
            'message' => ['conversation' => 'orphan'],
        ]));
    }

    public function testMentionsAndQuotesAreLifted(): void
    {
        $payload = $this->groupTextMessage();
        $payload['message']['extendedTextMessage']['contextInfo'] = [
            'stanzaId' => '3EB0PARENT',
            'mentionedJid' => [
                '100584006914211@lid',
                '15550003333@s.whatsapp.net',
            ],
        ];

        $inbound = InboundMessage::fromWebhookMessage($payload);

        $this->assertNotNull($inbound);
        $this->assertSame('3EB0PARENT', $inbound->quotedMessageId);
        $this->assertSame(
            [
                '100584006914211@lid',
                '15550003333@s.whatsapp.net',
            ],
            $inbound->mentionedJids
        );
    }

    /**
     * Verbatim from the 2026-08-19 production capture: a member @-mentions the agent by its bare
     * lid. The mention list carries `@lid` values — never the phone form.
     */
    public function testRealMentionPayloadLiftsTheBareLidMention(): void
    {
        $inbound = InboundMessage::fromWebhookMessage([
            'id' => '3EB091103A6134C7EF840C',
            'key' => [
                'id' => '3EB091103A6134C7EF840C',
                'fromMe' => false,
                'remoteJid' => '18097070426-1436467587@g.us',
                'participant' => '168968509780173@lid',
                'participantPn' => '18096573168@s.whatsapp.net',
                'addressingMode' => 'lid',
                'participantLid' => '168968509780173@lid',
                'cleanedParticipantPn' => '18096573168',
            ],
            'message' => [
                'extendedTextMessage' => [
                    'text' => '@171081616904236',
                    'contextInfo' => [
                        'mentionedJid' => ['171081616904236@lid'],
                        'conversionSource' => 'FB_Post',
                        'disappearingMode' => [
                            'trigger' => 'CHAT_SETTING',
                            'initiator' => 'CHANGED_IN_CHAT',
                            'initiatedByMe' => false,
                        ],
                    ],
                ],
            ],
            'pushName' => 'Rafael Zapata',
            'remoteJid' => '18097070426-1436467587@g.us',
            'messageBody' => '@171081616904236',
            'messageTimestamp' => 1787141648,
        ]);

        $this->assertNotNull($inbound);
        $this->assertSame(ConversationTypeEnum::GROUP, $inbound->conversationType);
        $this->assertSame(['171081616904236@lid'], $inbound->mentionedJids);
        $this->assertSame('168968509780173', $inbound->senderLid);
        $this->assertSame('18096573168', $inbound->senderPhone);
    }

    /**
     * Verbatim from the capture: a reply. The key is sparse — no participantLid, no
     * cleanedParticipantPn, no addressingMode — and the quoted author's lid rides on
     * contextInfo.participant.
     */
    public function testRealQuotePayloadResolvesSparseKeyAndQuotedAuthor(): void
    {
        $inbound = InboundMessage::fromWebhookMessage([
            'id' => '3EB05A258969B84AE73E45',
            'key' => [
                'id' => '3EB05A258969B84AE73E45',
                'fromMe' => false,
                'remoteJid' => '18097070426-1436467587@g.us',
                'participant' => '168968509780173@lid',
                'participantPn' => '18096573168@s.whatsapp.net',
            ],
            'message' => [
                'extendedTextMessage' => [
                    'text' => 'De acuerdo, don Persio.',
                    'contextInfo' => [
                        'stanzaId' => '3A281EFF2AE287257E08',
                        'participant' => '110114539327541@lid',
                        'quotedMessage' => [
                            'conversation' => 'Rafael, cubran esta actividad hoy. Tiene que ver con nosotros. Gracias.',
                        ],
                    ],
                ],
            ],
            'pushName' => 'Rafael Zapata',
            'remoteJid' => '18097070426-1436467587@g.us',
            'messageBody' => 'De acuerdo, don Persio.',
            'messageTimestamp' => 1787168344,
        ]);

        $this->assertNotNull($inbound);
        $this->assertSame('3A281EFF2AE287257E08', $inbound->quotedMessageId);
        $this->assertSame('110114539327541', $inbound->quotedParticipant);
        $this->assertSame('168968509780173', $inbound->senderLid, 'The lid falls back to key.participant');
        $this->assertSame('18096573168', $inbound->senderPhone, 'The phone falls back to key.participantPn');
    }

    private function groupTextMessage(): array
    {
        return [
            'key' => [
                'id' => '3EBTEXT0000000000001',
                'fromMe' => false,
                'remoteJid' => '15550001111-1700000000@g.us',
                'participant' => '900000000000001@lid',
                'participantPn' => '15550001111@s.whatsapp.net',
                'addressingMode' => 'lid',
                'participantLid' => '900000000000001@lid',
                'cleanedParticipantPn' => '15550001111',
            ],
            'messageTimestamp' => 1787146109,
            'pushName' => 'Alex Rivera',
            'broadcast' => false,
            'message' => [
                'messageContextInfo' => [
                    'messageSecret' => 'ZXhhbXBsZS1zZWNyZXQtZm9yLXRlc3RzLW9ubHkyPT09',
                ],
                'extendedTextMessage' => [
                    'text' => 'Press release body for the fixture',
                    'contextInfo' => [
                        'conversionSource' => 'FB_Post',
                    ],
                ],
            ],
            'remoteJid' => '15550001111-1700000000@g.us',
        ];
    }

    private function groupAlbumImage(string $messageId): array
    {
        return [
            'key' => [
                'id' => $messageId,
                'fromMe' => false,
                'remoteJid' => '15550001111-1700000000@g.us',
                'participant' => '900000000000002@lid',
                'participantPn' => '15550002222@s.whatsapp.net',
                'addressingMode' => 'lid',
                'participantLid' => '900000000000002@lid',
                'cleanedParticipantPn' => '15550002222',
            ],
            'pushName' => 'Sam Okafor',
            'message' => [
                'imageMessage' => [
                    'url' => 'https://mmg.whatsapp.net/o1/v/t24/f2/m000/EXAMPLE',
                    'mimetype' => 'image/jpeg',
                    'mediaKey' => 'ZXhhbXBsZS1tZWRpYS1rZXktZm9yLXRlc3RzLW9ubHk9',
                ],
                'messageContextInfo' => [
                    'messageSecret' => 'ZXhhbXBsZS1zZWNyZXQtZm9yLXRlc3RzLW9ubHk9PT09',
                    'messageAssociation' => [
                        'associationType' => 'MEDIA_ALBUM',
                        'parentMessageKey' => [
                            'id' => '3AALBUMPARENT000001',
                            'fromMe' => true,
                            'remoteJid' => '15550001111-1700000000@g.us',
                        ],
                    ],
                ],
            ],
        ];
    }
}
