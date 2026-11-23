<?php

declare(strict_types=1);

namespace Tests\Connectors\Stripe;

use Kanvas\Connectors\Stripe\Enums\CustomFieldEnum;
use Kanvas\Connectors\Stripe\Services\StripePaymentLinkService;
use Kanvas\Exceptions\ValidationException;
use Stripe\PaymentLink;
use Tests\Connectors\Stripe\Fakes\FakeStripeClient;
use Tests\Scribe\ScribeTestCase;

/**
 * A B2B customer pays an invoice's balance, not the order: invoice 2611599 is $687.76 due on receipt.
 */
final class StripeInvoicePaymentLinkTest extends ScribeTestCase
{
    private FakeStripeClient $stripe;

    protected function afterScribeSetUp(): void
    {
        $this->stripe = new FakeStripeClient();
    }

    private function service(): StripePaymentLinkService
    {
        return new StripePaymentLinkService($this->kanvasApp, $this->company, $this->stripe);
    }

    private function link(string $id, bool $active = true): PaymentLink
    {
        return PaymentLink::constructFrom(['id' => $id, 'url' => 'https://buy.stripe.com/' . $id, 'active' => $active]);
    }

    public function testTheLinkChargesExactlyTheBalanceAndCarriesTheInvoice(): void
    {
        $invoice = $this->importTestInvoice(687.76);
        $this->stripe->getPaymentLinks()->queueResponse('create', $this->link('plink_2611599'));

        $link = $this->service()->generatePaymentLinkFromInvoice($invoice);

        $params = $this->stripe->getPaymentLinks()->getCalls('create')[0]['params'];
        $this->assertSame('plink_2611599', $link->id);
        $this->assertSame(68776, $params['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('usd', $params['line_items'][0]['price_data']['currency']);
        $this->assertSame('Invoice ' . $invoice->invoice_number, $params['line_items'][0]['price_data']['product_data']['name']);
        $this->assertSame((string) $invoice->getId(), $params['metadata']['invoice_id']);
        $this->assertSame((string) $this->company->getId(), $params['metadata']['company_id']);
        $this->assertSame(['card'], $params['payment_method_types']);
        $stored = $invoice->fresh()->metadata[CustomFieldEnum::STRIPE_INVOICE_PAYMENT_LINK->value];
        $this->assertSame('plink_2611599', $stored['id']);
    }

    public function testAskingAgainForTheSameBalanceReturnsTheSameLink(): void
    {
        $invoice = $this->importTestInvoice(687.76);
        $this->stripe->getPaymentLinks()->queueResponse('create', $this->link('plink_first'));
        $this->service()->generatePaymentLinkFromInvoice($invoice);

        $this->stripe->getPaymentLinks()->queueResponse('retrieve', $this->link('plink_first'));
        $again = $this->service()->generatePaymentLinkFromInvoice($invoice->fresh());

        $this->assertSame('plink_first', $again->id);
        $this->assertCount(1, $this->stripe->getPaymentLinks()->getCalls('create'));
    }

    public function testAChangedBalanceRetiresTheOldLinkAndChargesTheNewBalance(): void
    {
        $invoice = $this->importTestInvoice(687.76);
        $this->stripe->getPaymentLinks()->queueResponse('create', $this->link('plink_full'));
        $this->service()->generatePaymentLinkFromInvoice($invoice);

        // NetSuite recorded $200 paid; the re-import refreshes the balance to $487.76.
        $invoice = $this->importTestInvoice(687.76, paid: 200.0, externalId: $invoice->external_id);
        $this->stripe->getPaymentLinks()->queueResponse('update', $this->link('plink_full', active: false));
        $this->stripe->getPaymentLinks()->queueResponse('create', $this->link('plink_rest'));

        $link = $this->service()->generatePaymentLinkFromInvoice($invoice);

        $this->assertSame('plink_rest', $link->id);
        $links = $this->stripe->getPaymentLinks();
        $this->assertSame(['id' => 'plink_full', 'active' => false], $links->getCalls('update')[0]['params']);
        $this->assertSame(48776, $links->getCalls('create')[1]['params']['line_items'][0]['price_data']['unit_amount']);
    }

    public function testAPaidInvoiceGetsNoLink(): void
    {
        $invoice = $this->importTestInvoice(100.0, paid: 100.0);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('only an issued or sent invoice can be paid');

        $this->service()->generatePaymentLinkFromInvoice($invoice);
    }
}
