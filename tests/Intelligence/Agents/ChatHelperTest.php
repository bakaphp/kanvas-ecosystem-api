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

    /**
     * The envelope is what the reply text throws away: an agent that answered with a whole record
     * keeps only its body once {@see ChatHelper::extractTextFromResponse()} picks a field.
     */
    public function testExtractsTheWholeEnvelopeFromAFencedReply(): void
    {
        $reply = "```json\n" . json_encode([
            'title' => 'Educación acelera construcción de aulas',
            'content' => '<p>Body.</p>',
            'tags' => ['Educación', 'El Seibo'],
        ], JSON_UNESCAPED_UNICODE) . "\n```";

        $envelope = ChatHelper::extractJsonEnvelope($reply);

        $this->assertSame('Educación acelera construcción de aulas', $envelope['title']);
        $this->assertSame(['Educación', 'El Seibo'], $envelope['tags']);
        $this->assertSame('<p>Body.</p>', ChatHelper::extractTextFromResponse($reply));
    }

    public function testEnvelopeIsNullForAPlainTextReply(): void
    {
        $this->assertNull(ChatHelper::extractJsonEnvelope("Hi Max,\n\nHappy to help."));
    }

    /**
     * A newsroom burst carrying two press releases comes back as a LIST of records. Anchoring the
     * fenced match on `{` left it unparsed, so the envelope was lost and the caller published the
     * model's raw JSON as the article body (prod, El Nuevo Diario).
     */
    public function testExtractsAFencedListOfRecordsAsTheEnvelope(): void
    {
        $envelope = ChatHelper::extractJsonEnvelope($this->twoArticles(fenced: true));

        $this->assertCount(2, $envelope);
        $this->assertSame('Primera nota', $envelope[0]['title']);
        $this->assertSame('Segunda nota', $envelope[1]['title']);
    }

    public function testExtractsABareListOfRecordsAsTheEnvelope(): void
    {
        $envelope = ChatHelper::extractJsonEnvelope($this->twoArticles(fenced: false));

        $this->assertCount(2, $envelope);
        $this->assertSame('Primera nota', $envelope[0]['title']);
    }

    /**
     * Every value in a list is an array, so the string scan found nothing and returned '' — and an
     * empty reply makes the responder throw the whole agent turn away.
     */
    public function testTheReplyTextOfAListIsTheFirstRecordNotEmpty(): void
    {
        foreach ([true, false] as $fenced) {
            $this->assertSame(
                '<p>Cuerpo de la primera.</p>',
                ChatHelper::extractTextFromResponse($this->twoArticles($fenced))
            );
        }
    }

    /**
     * Prose with a bracketed aside decodes as a valid JSON array. Treating that as structure handed
     * the caller one fragment of the list — or, when the members were numbers, an empty reply, which
     * the responder reads as "the agent said nothing" and throws the turn away.
     */
    public function testProseContainingABracketedListIsNotAnEnvelope(): void
    {
        $prose = 'Los pasos son [1, 2, 3] y nada mas.';

        $this->assertNull(ChatHelper::extractJsonEnvelope($prose));
        $this->assertSame($prose, ChatHelper::extractTextFromResponse($prose));
    }

    public function testProseContainingAQuotedListIsNotAnEnvelope(): void
    {
        $prose = 'Puedes elegir ["azul", "rojo"] cuando quieras.';

        $this->assertNull(ChatHelper::extractJsonEnvelope($prose));
        $this->assertSame($prose, ChatHelper::extractTextFromResponse($prose));
    }

    private function twoArticles(bool $fenced): string
    {
        $json = json_encode(
            [
                ['title' => 'Primera nota', 'content' => '<p>Cuerpo de la primera.</p>', 'status' => 'draft'],
                ['title' => 'Segunda nota', 'content' => '<p>Cuerpo de la segunda.</p>', 'status' => 'draft'],
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );

        return $fenced ? "```json\n" . $json . "\n```" : $json . "\n";
    }
}
