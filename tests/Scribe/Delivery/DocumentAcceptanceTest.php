<?php

declare(strict_types=1);

namespace Tests\Scribe\Delivery;

use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Scribe\Delivery\Actions\RecordDocumentResponseAction;
use Kanvas\Scribe\Delivery\Exceptions\DocumentDeliveryException;
use Kanvas\Scribe\Delivery\Models\DocumentAcceptance;
use Kanvas\Scribe\Quotes\Actions\SendQuoteAction;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Exceptions\InvalidQuoteTransitionException;
use Kanvas\Scribe\Quotes\Models\Quote;
use LogicException;

final class DocumentAcceptanceTest extends DocumentDeliveryTestCase
{
    private const string PIXEL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function test_the_first_signer_is_recorded_once_and_a_repeat_acceptance_does_not_replace_them(): void
    {
        [$quote, $engagement] = $this->sentQuoteWithSow();

        new RecordDocumentResponseAction(
            engagement: $engagement,
            status: ActionStatusEnum::SUBMITTED,
            signerName: 'Jane Customer',
            ipAddress: '203.0.113.7',
            userAgent: 'Browser/1.0',
        )->execute();

        new RecordDocumentResponseAction(
            engagement: $engagement,
            status: ActionStatusEnum::SUBMITTED,
            signerName: 'Mallory Replay',
        )->execute();

        $acceptances = DocumentAcceptance::query()->where('quote_id', $quote->getId())->get();
        $this->assertCount(1, $acceptances);

        $acceptance = $acceptances->first();
        $this->assertSame('Jane Customer', $acceptance->signer_name);
        $this->assertSame((int) $quote->revision_number, $acceptance->quote_revision);
        $this->assertSame($quote->refresh()->sow_message_id, $acceptance->sow_message_id);
        $this->assertSame(64, strlen((string) $acceptance->sow_hash));
        $this->assertSame($engagement->people_id, $acceptance->people_id);
        $this->assertSame('ana.billing@example.com', $acceptance->signer_email);
        $this->assertSame('203.0.113.7', $acceptance->ip_address);
        $this->assertSame('Browser/1.0', $acceptance->user_agent);
        $this->assertNotNull($acceptance->engagement_id);
        $this->assertSame(QuoteStatusEnum::ACCEPTED, $quote->status);
        $this->assertArrayNotHasKey('acceptance', $quote->metadata ?? []);
    }

    public function test_an_acceptance_the_quote_refuses_leaves_no_acceptance_and_no_signature(): void
    {
        [$quote, $engagement] = $this->sentQuoteWithSow();
        new RecordDocumentResponseAction(
            engagement: $engagement,
            status: ActionStatusEnum::DECLINED,
            reason: 'We went with another vendor',
        )->execute();

        try {
            new RecordDocumentResponseAction(
                engagement: $engagement,
                status: ActionStatusEnum::SUBMITTED,
                signerName: 'Jane Customer',
                signature: self::PIXEL,
            )->execute();
            $this->fail('A rejected quote was accepted.');
        } catch (InvalidQuoteTransitionException|DocumentDeliveryException) {
        }

        $quote->refresh();
        $this->assertSame(QuoteStatusEnum::REJECTED, $quote->status);
        $this->assertFalse(DocumentAcceptance::query()->where('quote_id', $quote->getId())->exists());
        $this->assertArrayNotHasKey('acceptance', $quote->metadata ?? []);
        $this->assertNull($quote->getFileByName(RecordDocumentResponseAction::SIGNATURE_FIELD));
    }

    public function test_an_acceptance_cannot_be_deleted_or_edited(): void
    {
        [$quote, $engagement] = $this->sentQuoteWithSow();
        new RecordDocumentResponseAction(
            engagement: $engagement,
            status: ActionStatusEnum::SUBMITTED,
            signerName: 'Jane Customer',
        )->execute();

        $acceptance = DocumentAcceptance::query()->where('quote_id', $quote->getId())->firstOrFail();

        try {
            $acceptance->delete();
            $this->fail('An acceptance was deleted.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $acceptance->signer_name = 'Someone Else';
        $acceptance->save();
    }

    /**
     * @return array{0: Quote, 1: Engagement}
     */
    private function sentQuoteWithSow(): array
    {
        $action = new SendQuoteAction(
            quote: $this->draftQuoteWithSow(),
            billable: $this->customer,
            user: static::$cachedUser,
            deliverTo: $this->contact,
        );
        $quote = $action->execute();

        return [$quote, $action->delivery->engagement];
    }
}
