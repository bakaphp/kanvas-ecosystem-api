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
}
