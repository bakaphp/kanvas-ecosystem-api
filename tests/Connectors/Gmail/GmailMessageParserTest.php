<?php

declare(strict_types=1);

namespace Tests\Connectors\Gmail;

use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\MessagePartBody;
use Google\Service\Gmail\MessagePartHeader;
use Kanvas\Connectors\Gmail\Support\GmailMessageParser;
use Tests\TestCase;

class GmailMessageParserTest extends TestCase
{
    public function test_find_header_matches_case_insensitively(): void
    {
        $headers = [
            new MessagePartHeader(['name' => 'From', 'value' => 'vendor@windwalk.com']),
            new MessagePartHeader(['name' => 'Subject', 'value' => 'Invoice #4521']),
        ];

        $this->assertSame('vendor@windwalk.com', GmailMessageParser::findHeader($headers, 'from'));
        $this->assertSame('Invoice #4521', GmailMessageParser::findHeader($headers, 'SUBJECT'));
        $this->assertNull(GmailMessageParser::findHeader($headers, 'Date'));
    }

    public function test_extract_body_prefers_the_first_plain_and_html_part_found_in_a_multipart_tree(): void
    {
        $plainData = strtr(base64_encode('Hello plain'), '+/', '-_');
        $htmlData = strtr(base64_encode('<p>Hello html</p>'), '+/', '-_');

        $payload = new MessagePart([
            'mimeType' => 'multipart/alternative',
            'parts' => [
                new MessagePart(['mimeType' => 'text/plain', 'body' => new MessagePartBody(['data' => $plainData])]),
                new MessagePart(['mimeType' => 'text/html', 'body' => new MessagePartBody(['data' => $htmlData])]),
            ],
        ]);

        $body = GmailMessageParser::extractBody($payload);

        $this->assertSame('Hello plain', $body['plain']);
        $this->assertSame('<p>Hello html</p>', $body['html']);
    }

    public function test_extract_attachments_collects_every_part_with_a_filename_anywhere_in_the_tree(): void
    {
        $payload = new MessagePart([
            'mimeType' => 'multipart/mixed',
            'parts' => [
                new MessagePart(['mimeType' => 'text/plain', 'body' => new MessagePartBody(['data' => 'aGk'])]),
                new MessagePart([
                    'mimeType' => 'application/pdf',
                    'filename' => 'invoice-4521.pdf',
                    'body' => new MessagePartBody(['attachmentId' => 'ATTACH_1', 'size' => 1024]),
                ]),
            ],
        ]);

        $attachments = GmailMessageParser::extractAttachments($payload);

        $this->assertCount(1, $attachments);
        $this->assertSame('ATTACH_1', $attachments[0]['attachment_id']);
        $this->assertSame('invoice-4521.pdf', $attachments[0]['filename']);
        $this->assertSame('application/pdf', $attachments[0]['mime_type']);
    }

    public function test_decode_base64_url_handles_the_dash_underscore_alphabet(): void
    {
        $encoded = strtr(base64_encode('hello world'), '+/', '-_');

        $this->assertSame('hello world', GmailMessageParser::decodeBase64Url($encoded));
    }
}
