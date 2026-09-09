<?php

declare(strict_types=1);

namespace Tests\Guild\Customers;

use Kanvas\Guild\Customers\Enums\ConsentSignalEnum;
use Kanvas\Guild\Customers\Services\ConsentKeywordService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCaseUnit;

final class ConsentKeywordServiceTest extends TestCaseUnit
{
    public static function stopKeywordProvider(): array
    {
        return [
            'stop' => ['STOP'],
            'lowercase' => ['stop'],
            'mixed case' => ['Stop'],
            'trailing period' => ['STOP.'],
            'exclamation' => ['stop!'],
            'surrounding whitespace' => ["  stop \n"],
            'stopall' => ['STOPALL'],
            'stop all spaced' => ['STOP ALL'],
            'unsubscribe' => ['unsubscribe'],
            'cancel' => ['CANCEL'],
            'end' => ['end'],
            'quit' => ['QUIT'],
            'revoke' => ['revoke'],
            'optout' => ['OPTOUT'],
            'opt-out hyphenated' => ['opt-out'],
            'opt out spaced' => ['Opt Out'],
        ];
    }

    #[DataProvider('stopKeywordProvider')]
    public function testDetectsStopKeywords(string $body): void
    {
        $this->assertSame(ConsentSignalEnum::STOP, ConsentKeywordService::detect($body));
        $this->assertTrue(ConsentKeywordService::isStop($body));
    }

    public static function notAStopProvider(): array
    {
        return [
            'stop inside a sentence' => ['stop by the dealership tomorrow'],
            'stop as a request' => ['please stop texting me'],
            'cancel an appointment' => ['I need to cancel my appointment on friday'],
            'end of lease' => ['when does my lease end'],
            'quit in context' => ['I quit my job so I need a cheaper payment'],
            'empty' => [''],
            'whitespace only' => ["   \n  "],
            'null' => [null],
            'digits only' => ['12345'],
        ];
    }

    #[DataProvider('notAStopProvider')]
    public function testDoesNotDetectStopInProse(?string $body): void
    {
        $this->assertNotSame(ConsentSignalEnum::STOP, ConsentKeywordService::detect($body));
        $this->assertFalse(ConsentKeywordService::isStop($body));
    }

    public function testDetectsStartAndHelp(): void
    {
        $this->assertSame(ConsentSignalEnum::START, ConsentKeywordService::detect('START'));
        $this->assertSame(ConsentSignalEnum::START, ConsentKeywordService::detect('unstop'));
        $this->assertSame(ConsentSignalEnum::START, ConsentKeywordService::detect('Yes'));
        $this->assertSame(ConsentSignalEnum::HELP, ConsentKeywordService::detect('help'));
    }

    /**
     * Email is the reason first-line matching exists: a one-word reply always arrives wrapped in a
     * signature and a quoted thread, and would never match as a whole body.
     */
    public function testDetectsStopOnFirstLineOfAnEmailReply(): void
    {
        $body = "Unsubscribe\n\n--\nSent from my iPhone\n\n> On Tue, someone wrote:\n> Hi, checking in about the Civic";

        $this->assertSame(ConsentSignalEnum::STOP, ConsentKeywordService::detect($body));
    }

    public function testIgnoresKeywordBuriedInQuotedThread(): void
    {
        $body = "Thanks for the update, sounds good\n\n> Reply STOP to opt out";

        $this->assertNull(ConsentKeywordService::detect($body));
    }

    /**
     * The first-line pass bails on the signature delimiter, but the whole-body pass still matches:
     * everything in this message is either decoration or the keyword. Honoring it is the safe
     * direction — the whole-body pass can only ever fire when there is nothing else being said.
     */
    public function testKeywordUnderASignatureDelimiterStillMatchesAsAWholeBody(): void
    {
        $this->assertSame(ConsentSignalEnum::STOP, ConsentKeywordService::detect("--\nSTOP"));
    }

    public function testOversizedBodyIsNotScanned(): void
    {
        $this->assertNull(ConsentKeywordService::detect(str_repeat('a', 4096)));
    }

    public static function noContactPhraseProvider(): array
    {
        return [
            'plain' => ['please stop texting me'],
            'apostrophe' => ["don't contact me again"],
            'curly apostrophe' => ['don’t contact me again'],
            'hyphenated' => ['this is a do-not-contact request'],
            'take me off' => ['take me off your list please'],
            'remove me' => ['remove me from your mailing list'],
            'spanish no llamar' => ['por favor no llamar mas'],
            'spanish no me escribas' => ['no me escribas mas por favor'],
            'dnc shorthand' => ['customer says DNC'],
            'stop reaching out' => ['I would like you to stop reaching out'],
        ];
    }

    #[DataProvider('noContactPhraseProvider')]
    public function testMatchesNoContactPhrase(string $text): void
    {
        $this->assertTrue(ConsentKeywordService::matchesNoContactPhrase($text));
        $this->assertNull(
            ConsentKeywordService::detect($text),
            'phrases must NOT come back as exact keyword matches.',
        );
    }

    public static function innocentTextProvider(): array
    {
        return [
            'buying signal' => ['I am interested, what is the price on the Civic?'],
            'dnc inside a word' => ['my account number is abcdncxyz'],
            'contact as a noun' => ['who is my main contact there?'],
            'calling about' => ['I am calling about the trade in value'],
            'empty' => [''],
            'null' => [null],
        ];
    }

    #[DataProvider('innocentTextProvider')]
    public function testDoesNotMatchInnocentText(?string $text): void
    {
        $this->assertFalse(ConsentKeywordService::matchesNoContactPhrase($text));
    }

    /**
     * The false positives that cost a live customer: both trip a no-contact phrase while asking us to
     * keep talking, so the caller must treat them as a preference, not a revocation.
     */
    public static function narrowedRequestProvider(): array
    {
        return [
            'channel swap' => ["don't email me, text me instead"],
            'use my other number' => ['do not call this line, use my cell'],
            'time window' => ["don't call me before 5pm"],
            'time window after' => ['do not text after 9pm please'],
            'weekend' => ['do not contact me on the weekend'],
            'spanish channel swap' => ['no llamar, mejor escribeme'],
        ];
    }

    #[DataProvider('narrowedRequestProvider')]
    public function testNarrowedRequestsAreRecognised(string $text): void
    {
        $this->assertTrue(
            ConsentKeywordService::matchesNoContactPhrase($text),
            'sanity: these do trip the phrase matcher.',
        );
        $this->assertTrue(
            ConsentKeywordService::narrowsRequestScope($text),
            'and must be recognised as narrowed, so they are never auto-applied.',
        );
    }

    public static function unqualifiedRequestProvider(): array
    {
        return [
            'flat' => ['please stop contacting me'],
            'again is emphasis not a qualifier' => ["don't contact me again"],
            'remove' => ['take me off your list'],
        ];
    }

    #[DataProvider('unqualifiedRequestProvider')]
    public function testRealOptOutsAreNotTreatedAsNarrowed(string $text): void
    {
        $this->assertTrue(ConsentKeywordService::matchesNoContactPhrase($text));
        $this->assertFalse(ConsentKeywordService::narrowsRequestScope($text));
    }

    /**
     * Unlike exact matching, a phrase can sit anywhere inside a long email, so the length cap has to
     * be far looser than the keyword one.
     */
    public function testPhraseIsFoundInsideALongBody(): void
    {
        $body = str_repeat('Thanks for the detailed quote breakdown. ', 60)
            . 'Anyway please take me off your list.';

        $this->assertTrue(ConsentKeywordService::matchesNoContactPhrase($body));
    }

    public static function ambiguousStopKeywordProvider(): array
    {
        return [
            'cancel' => ['CANCEL'],
            'cancel lowercase' => ['cancel'],
            'cancel punctuated' => ['Cancel.'],
            'end' => ['END'],
            'end lowercase' => ['end'],
        ];
    }

    /**
     * Both are FCC keywords, so they are honored like any other — but they are also what someone
     * writes to cancel an appointment, and the opt-out they trigger is person-wide across every
     * channel and lead. The tier is what puts the review prompt on the lead note.
     */
    #[DataProvider('ambiguousStopKeywordProvider')]
    public function testAmbiguousStopKeywordsStillStopButAreFlaggedForReview(string $body): void
    {
        $this->assertSame(ConsentSignalEnum::STOP, ConsentKeywordService::detect($body));
        $this->assertTrue(ConsentKeywordService::stopKeywordIsAmbiguous($body));
    }

    public static function unambiguousStopKeywordProvider(): array
    {
        return [
            'stop' => ['STOP'],
            'unsubscribe' => ['unsubscribe'],
            'stopall' => ['STOPALL'],
            'quit' => ['QUIT'],
            'revoke' => ['revoke'],
            'optout' => ['opt-out'],
        ];
    }

    #[DataProvider('unambiguousStopKeywordProvider')]
    public function testUnambiguousStopKeywordsNeedNoReview(string $body): void
    {
        $this->assertSame(ConsentSignalEnum::STOP, ConsentKeywordService::detect($body));
        $this->assertFalse(ConsentKeywordService::stopKeywordIsAmbiguous($body));
    }

    public function testAnOrdinaryMessageIsNotAnAmbiguousKeyword(): void
    {
        $this->assertFalse(ConsentKeywordService::stopKeywordIsAmbiguous('can we cancel the 3pm and meet Friday?'));
        $this->assertFalse(ConsentKeywordService::stopKeywordIsAmbiguous(null));
    }

    public function testExtractReasonDropsTheQuotedThreadAndSignature(): void
    {
        $body = <<<'EMAIL'
            Please take me off your list.

            --
            Jane Doe · VP Sales · 809-555-0147

            > On Mon, Sep 7, 2026, sales@dealer.com wrote:
            > Following up on the Civic you looked at. Financing is approved at 6.9%.
            EMAIL;

        $this->assertSame(
            'Please take me off your list.',
            ConsentKeywordService::extractReason($body),
        );
    }

    /**
     * The reason is stored on the person, on every one of their leads, and in a lead note, so a
     * body with no signature to stop at still must not write an unbounded blob.
     */
    public function testExtractReasonClampsALongBody(): void
    {
        $reason = ConsentKeywordService::extractReason(str_repeat('remove me from your list. ', 200));

        $this->assertNotNull($reason);
        $this->assertLessThanOrEqual(ConsentKeywordService::MAX_REASON_LENGTH + 3, mb_strlen($reason));
    }

    public function testExtractReasonIsNullWhenThereIsNothingToStore(): void
    {
        $this->assertNull(ConsentKeywordService::extractReason(null));
        $this->assertNull(ConsentKeywordService::extractReason('   '));
        $this->assertNull(ConsentKeywordService::extractReason("> only a quoted line\n> and another"));
    }
}
