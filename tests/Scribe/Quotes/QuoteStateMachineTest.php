<?php

declare(strict_types=1);

namespace Tests\Scribe\Quotes;

use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Exceptions\InvalidQuoteTransitionException;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Services\QuoteStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuoteStateMachineTest extends TestCase
{
    private QuoteStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->machine = new QuoteStateMachine();
    }

    #[DataProvider('validTransitionProvider')]
    public function test_valid_transition_passes(QuoteStatusEnum $from, QuoteStatusEnum $to): void
    {
        $quote = new Quote();
        $quote->status = $from;
        $this->machine->assertTransition($quote, $to);
        $this->assertTrue(true, "{$from->value} → {$to->value} should be allowed.");
    }

    public static function validTransitionProvider(): array
    {
        return [
            'draft → sent' => [QuoteStatusEnum::DRAFT, QuoteStatusEnum::SENT],
            'draft → superseded (via revision)' => [QuoteStatusEnum::DRAFT, QuoteStatusEnum::SUPERSEDED],
            'sent → accepted' => [QuoteStatusEnum::SENT, QuoteStatusEnum::ACCEPTED],
            'sent → rejected' => [QuoteStatusEnum::SENT, QuoteStatusEnum::REJECTED],
            'sent → expired' => [QuoteStatusEnum::SENT, QuoteStatusEnum::EXPIRED],
            'sent → superseded' => [QuoteStatusEnum::SENT, QuoteStatusEnum::SUPERSEDED],
            'accepted → converted' => [QuoteStatusEnum::ACCEPTED, QuoteStatusEnum::CONVERTED],
            'sent → sent (idempotent)' => [QuoteStatusEnum::SENT, QuoteStatusEnum::SENT],
        ];
    }

    #[DataProvider('invalidTransitionProvider')]
    public function test_invalid_transition_throws(QuoteStatusEnum $from, QuoteStatusEnum $to): void
    {
        $quote = new Quote();
        $quote->status = $from;
        $this->expectException(InvalidQuoteTransitionException::class);
        $this->machine->assertTransition($quote, $to);
    }

    public static function invalidTransitionProvider(): array
    {
        return [
            'draft → accepted (must go via sent)' => [QuoteStatusEnum::DRAFT, QuoteStatusEnum::ACCEPTED],
            'draft → converted (must go via accepted)' => [QuoteStatusEnum::DRAFT, QuoteStatusEnum::CONVERTED],
            'accepted → rejected (terminal)' => [QuoteStatusEnum::ACCEPTED, QuoteStatusEnum::REJECTED],
            'rejected → accepted (terminal)' => [QuoteStatusEnum::REJECTED, QuoteStatusEnum::ACCEPTED],
            'expired → accepted (terminal)' => [QuoteStatusEnum::EXPIRED, QuoteStatusEnum::ACCEPTED],
            'converted → anything' => [QuoteStatusEnum::CONVERTED, QuoteStatusEnum::SENT],
            'superseded → anything' => [QuoteStatusEnum::SUPERSEDED, QuoteStatusEnum::ACCEPTED],
        ];
    }
}
