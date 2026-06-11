<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Tests\TestCase;

class ChatHelperTest extends TestCase
{
    public function testExtractsLeadingSubjectLineFromEmailBody(): void
    {
        $text = "Subject: Eliminating fragile agent workflows in your sales and AP\n\nHi Max,\n\nLet's talk.";

        $parsed = ChatHelper::extractEmailSubjectAndBody($text);

        $this->assertSame('Eliminating fragile agent workflows in your sales and AP', $parsed['subject']);
        $this->assertSame("Hi Max,\n\nLet's talk.", $parsed['body']);
        $this->assertStringNotContainsString('Subject:', $parsed['body']);
    }

    public function testReturnsNullSubjectWhenNoSubjectLinePresent(): void
    {
        $text = "Hi Max,\n\nJust checking in on your order.";

        $parsed = ChatHelper::extractEmailSubjectAndBody($text);

        $this->assertNull($parsed['subject']);
        $this->assertSame($text, $parsed['body']);
    }

    public function testOnlyStripsTheFirstSubjectLine(): void
    {
        $text = "Subject: Quick question\n\nSubject lines can appear inside the body too.";

        $parsed = ChatHelper::extractEmailSubjectAndBody($text);

        $this->assertSame('Quick question', $parsed['subject']);
        $this->assertSame('Subject lines can appear inside the body too.', $parsed['body']);
    }

    public function testReturnsResponseFieldDirectlyFromJson(): void
    {
        $json = json_encode(['response' => 'Hi Alan, here are three slots.']);

        $this->assertSame('Hi Alan, here are three slots.', ChatHelper::extractTextFromResponse($json));
    }

    public function testDoesNotDuplicateBodyWhenJsonHasMultipleStringFields(): void
    {
        // Regression: the old "join every string value" fallback rendered this as
        // `body + "\n\n" + message`, doubling the outbound email.
        $json = json_encode([
            'body' => "Hi Alan,\n\nSaludos,\n\nSally Castro | Kanvas",
            'message' => "Hi Alan,\n\nSaludos,",
        ]);

        $result = ChatHelper::extractTextFromResponse($json);

        // Picks exactly one field — never the two glued together.
        $this->assertSame(1, substr_count($result, 'Saludos,'), 'Body must appear exactly once');
        $this->assertSame("Hi Alan,\n\nSaludos,", $result);
    }

    public function testFallsBackToLongestStringWhenNoKnownContentKey(): void
    {
        $json = json_encode([
            'note' => 'short',
            'draft' => 'this is the much longer customer-facing draft body',
        ]);

        $this->assertSame(
            'this is the much longer customer-facing draft body',
            ChatHelper::extractTextFromResponse($json),
        );
    }

    public function testReturnsPlainTextUnchanged(): void
    {
        $text = "Hi Alan,\n\nJust a plain reply.";

        $this->assertSame($text, ChatHelper::extractTextFromResponse($text));
    }

    public function testExtractsStructuredSubjectAndBodyFromJsonEnvelope(): void
    {
        $json = json_encode([
            'subject' => 'Eliminating fragile agent workflows',
            'response' => "Hi Max,\n\nLet's talk.",
        ]);

        $this->assertSame('Eliminating fragile agent workflows', ChatHelper::extractSubjectFromResponse($json));
        // Body comes back clean — no Subject line embedded.
        $this->assertSame("Hi Max,\n\nLet's talk.", ChatHelper::extractTextFromResponse($json));
    }

    public function testSubjectIsNullWhenResponseIsPlainText(): void
    {
        $this->assertNull(ChatHelper::extractSubjectFromResponse("Subject: foo\n\nHi Max"));
    }

    public function testSubjectIsNullWhenEnvelopeHasNoSubjectField(): void
    {
        $json = json_encode(['response' => 'Hi Max, no subject here.']);

        $this->assertNull(ChatHelper::extractSubjectFromResponse($json));
    }

    public function testCollapsesWholeReplyEmittedTwiceInsideOneField(): void
    {
        // The model doubled the body inside a single `response` field — selecting one
        // field can't help, the collapse must.
        $body = "Hi Max,\n\nOf course you can reschedule.\n\nBest,\n\nSally Castro | Kanvas";
        $json = json_encode(['response' => $body . "\n\n" . $body]);

        $result = ChatHelper::extractTextFromResponse($json);

        $this->assertSame($body, $result);
        $this->assertSame(1, substr_count($result, 'Of course you can reschedule.'));
    }

    public function testCollapsesWholeReplyEmittedTwiceInPlainText(): void
    {
        $body = "Hi Max,\n\nLet's reschedule.\n\nBest,\n\nSally";

        $this->assertSame($body, ChatHelper::extractTextFromResponse($body . "\n\n" . $body));
    }

    public function testDoesNotTruncateANormalReply(): void
    {
        // Even paragraph count, but halves differ — must stay intact.
        $body = "Hi Max,\n\nFirst point here.\n\nSecond, different point.\n\nBest, Sally";

        $this->assertSame($body, ChatHelper::extractTextFromResponse($body));
    }
}
