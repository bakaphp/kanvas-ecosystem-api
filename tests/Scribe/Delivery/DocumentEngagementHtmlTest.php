<?php

declare(strict_types=1);

namespace Tests\Scribe\Delivery;

use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Scribe\Delivery\Actions\OpenDocumentEngagementAction;
use Kanvas\Scribe\Delivery\Enums\DocumentActionEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum as EcfConfigurationEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Models\Quote;

/**
 * The customer page (Customer Actions viewer) shows `message.data.html` in a sandboxed iframe and looks
 * the engagement up by its own uuid; the HTML is the string the PDF is printed from.
 */
final class DocumentEngagementHtmlTest extends DocumentDeliveryTestCase
{
    public function test_an_accepted_e_cf_invoice_carries_its_legal_representation(): void
    {
        $invoice = $this->acceptedInvoice();

        $data = $this->dataOf($this->send($invoice)->engagement);

        $this->assertSame($invoice->invoice_number, $data['document_number']);
        $this->assertSame('Invoice · ' . $invoice->invoice_number, $data['name']);
        $this->assertStringContainsString('E310000000004', $data['html']);
        $this->assertStringContainsString('ABC123', $data['html']);
        $this->assertStringContainsString('<style>', $data['html']);
        $this->assertStringNotContainsString('<script', $data['html']);
    }

    public function test_an_e_cf_invoice_the_authority_has_not_accepted_carries_no_html(): void
    {
        $invoice = $this->issue($this->draftInvoice($this->customer));

        $data = $this->dataOf($this->open($invoice, DocumentActionEnum::VIEW_INVOICE));

        $this->assertSame($invoice->invoice_number, $data['document_number']);
        $this->assertArrayNotHasKey('html', $data);
    }

    public function test_an_invoice_outside_e_invoicing_carries_the_plain_layout(): void
    {
        $this->company->del(EcfConfigurationEnum::ECF_PROVIDER->value);
        $invoice = $this->issue($this->draftInvoice($this->customer));

        $data = $this->dataOf($this->open($invoice, DocumentActionEnum::VIEW_INVOICE));

        $this->assertStringContainsString('Servicio de consultoría', $data['html']);
        $this->assertStringContainsString($invoice->invoice_number, $data['html']);
        $this->assertStringNotContainsString('E310000000004', $data['html']);
    }

    public function test_a_quote_carries_its_layout_and_a_sow_its_own(): void
    {
        $quote = $this->draftQuote();
        $viewed = $this->dataOf($this->open($quote, DocumentActionEnum::VIEW_QUOTE));

        $this->assertSame('Quote · ' . $quote->documentNumber(), $viewed['name']);
        $this->assertStringContainsString('Discovery workshop', $viewed['html']);
        $this->assertStringContainsString('Mobile app build', $viewed['html']);

        $withSow = $this->draftQuoteWithSow();
        $signed = $this->dataOf($this->open($withSow, DocumentActionEnum::SIGN_SOW));

        $this->assertSame('Statement of Work · ' . $withSow->documentNumber(), $signed['name']);
        $this->assertStringContainsString('Build the app.', $signed['html']);
        $this->assertStringContainsString('Deposit', $signed['html']);
    }

    public function test_a_reused_engagement_shows_the_document_as_it_is_now_and_keeps_its_link(): void
    {
        $quote = $this->draftQuote();
        $first = $this->open($quote, DocumentActionEnum::VIEW_QUOTE);
        $firstLink = $first->message->getMessage()['action_link'];
        $this->assertStringNotContainsString('Pay by wire within 5 days', $this->dataOf($first)['html']);

        $quote->notes = 'Pay by wire within 5 days';
        $quote->saveOrFail();
        $second = $this->open($quote->refresh(), DocumentActionEnum::VIEW_QUOTE);

        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame($firstLink, $second->message->getMessage()['action_link']);
        $this->assertStringContainsString('Pay by wire within 5 days', $this->dataOf($second)['html']);
        $this->assertStringContainsString('Pay by wire within 5 days', $this->dataOf($second->refresh())['html']);
    }

    private function open(Invoice|Quote $document, DocumentActionEnum $action): Engagement
    {
        return new OpenDocumentEngagementAction(
            document: $document,
            people: $this->contact,
            user: static::$cachedUser,
            action: $action,
        )->execute()->engagement;
    }

    /**
     * @return array<string, mixed>
     */
    private function dataOf(Engagement $engagement): array
    {
        return $engagement->refresh()->message->getMessage()['data'];
    }
}
