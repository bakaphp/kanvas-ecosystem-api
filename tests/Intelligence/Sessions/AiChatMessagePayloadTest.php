<?php

declare(strict_types=1);

namespace Tests\Intelligence\Sessions;

use Kanvas\Intelligence\Sessions\DataTransferObject\AiChatMessagePayload;
use Tests\TestCase;

/**
 * Pins the canonical shape of `messages.message` for AI chat writes — every consumer reads
 * these keys, so drift here breaks the connector responders + the interactive UI in one go.
 */
class AiChatMessagePayloadTest extends TestCase
{
    public function testInteractiveShapeOmitsConnectorOnlyKeys(): void
    {
        $payload = new AiChatMessagePayload(
            content: 'hello agent',
            from_me: false,
            from_ia: false,
            session_id: 'sess-uuid-123',
            agent_id: 42,
            images: ['https://cdn.example.com/a.png'],
        );

        $this->assertSame(
            [
                'content' => 'hello agent',
                'from_me' => false,
                'from_ia' => false,
                'session_id' => 'sess-uuid-123',
                'agent_id' => 42,
                'images' => ['https://cdn.example.com/a.png'],
            ],
            $payload->toArray(),
        );
    }

    public function testConnectorOutboundShapeCarriesRawDataAndChatJid(): void
    {
        $payload = new AiChatMessagePayload(
            content: 'the agent reply',
            from_me: true,
            from_ia: true,
            agent_id: 7,
            raw_data: 'the agent reply',
            message_id: '--',
            chat_jid: '+15551234567',
        );

        $array = $payload->toArray();

        $this->assertSame('the agent reply', $array['content']);
        $this->assertTrue($array['from_me']);
        $this->assertTrue($array['from_ia']);
        $this->assertSame(7, $array['agent_id']);
        $this->assertSame('the agent reply', $array['raw_data']);
        $this->assertSame('--', $array['message_id']);
        $this->assertSame('+15551234567', $array['chat_jid']);
        $this->assertSame([], $array['images']);
        $this->assertArrayNotHasKey('session_id', $array, 'session_id must be omitted when null');
    }

    public function testNullableFieldsAreStrippedFromTheStoredJson(): void
    {
        $payload = new AiChatMessagePayload(
            content: 'minimal',
            from_me: false,
            from_ia: false,
        );

        $array = $payload->toArray();

        $this->assertSame(
            ['content', 'from_me', 'from_ia', 'images'],
            array_keys($array),
            'Only required fields and the always-present images list should land in the JSON',
        );
        $this->assertSame([], $array['images']);
    }

    public function testImagesDefaultsToEmptyListAndIsAlwaysPresent(): void
    {
        $payload = new AiChatMessagePayload(
            content: 'no attachments',
            from_me: true,
            from_ia: true,
        );

        $this->assertArrayHasKey('images', $payload->toArray());
        $this->assertSame([], $payload->toArray()['images']);
    }

    public function testInboundWebhookShapeCarriesArrayRawData(): void
    {
        $request = [
            'Body' => 'hi from sms',
            'From' => '+15551112222',
            'To' => '+15553334444',
            'SmsMessageSid' => 'SM123',
        ];

        $array = AiChatMessagePayload::from([
            'content' => $request['Body'],
            'from_me' => false,
            'from_ia' => false,
            'raw_data' => $request,
            'message_id' => $request['SmsMessageSid'],
            'chat_jid' => $request['From'],
        ])->toArray();

        $this->assertSame($request, $array['raw_data'], 'Inbound webhooks store the full request as raw_data');
        $this->assertSame('SM123', $array['message_id']);
        $this->assertSame('+15551112222', $array['chat_jid']);
        $this->assertFalse($array['from_me']);
        $this->assertFalse($array['from_ia']);
    }

    public function testConnectorsCanExtendThePayloadWithPerServiceKeysViaArraySpread(): void
    {
        $message = [
            ...AiChatMessagePayload::from([
                'content' => 'subject line preview',
                'from_me' => false,
                'from_ia' => false,
                'raw_data' => 'subject line preview',
                'message_id' => '--',
                'chat_jid' => 'email-jdoe-at-example-com',
            ])->toArray(),
            'from_email' => 'jdoe@example.com',
            'subject' => 'Re: quote follow-up',
        ];

        $this->assertSame('jdoe@example.com', $message['from_email']);
        $this->assertSame('Re: quote follow-up', $message['subject']);
        $this->assertSame('subject line preview', $message['content']);
        $this->assertSame('email-jdoe-at-example-com', $message['chat_jid']);
    }

    public function testNullContentIsAcceptedAndCoercedToEmptyStringInStoredJson(): void
    {
        $payload = new AiChatMessagePayload(
            content: null,
            from_me: false,
            from_ia: false,
        );

        $array = $payload->toArray();

        $this->assertArrayHasKey('content', $array, 'content key must always be present, even when null');
        $this->assertSame('', $array['content'], 'null content is coerced to empty string so downstream readers stay safe');
    }

    public function testFromArrayAcceptsNullContentForMediaOnlyWebhooks(): void
    {
        // Sentry KANVAS-ECOSYSTEM-5QA — Twilio MMS image-only payload has Body=null.
        $twilioMmsRequest = [
            'Body' => null,
            'From' => '+13177719667',
            'To' => '+12192442176',
            'NumMedia' => 1,
            'MediaUrl0' => 'https://api.twilio.com/.../Media/ME7faf8667484d507d9c29153f4290fb6e',
            'SmsMessageSid' => 'MMa1f3c622cb7b7004f9c93b9beb4aabea',
        ];

        $array = AiChatMessagePayload::from([
            'content' => $twilioMmsRequest['Body'],
            'from_me' => false,
            'from_ia' => false,
            'raw_data' => $twilioMmsRequest,
            'message_id' => $twilioMmsRequest['SmsMessageSid'],
            'chat_jid' => $twilioMmsRequest['From'],
        ])->toArray();

        $this->assertSame('', $array['content']);
        $this->assertSame($twilioMmsRequest, $array['raw_data']);
        $this->assertSame('MMa1f3c622cb7b7004f9c93b9beb4aabea', $array['message_id']);
    }
}
