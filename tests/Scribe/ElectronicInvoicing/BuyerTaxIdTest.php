<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Organizations\Enums\TaxIdTypeEnum;
use Kanvas\Guild\Organizations\Models\Address;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Locations\Models\Countries;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeResolver;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfIssuanceService;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\SubmitInvoiceForApprovalAction;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Tests\Stubs\Scribe\FakeEcfProvider;

/**
 * A 9-digit id is an RNC and a US EIN alike, so a Dominican issuer tells them apart by the customer's own country
 * and, before an E31 uses a number, by asking DGII.
 */
class BuyerTaxIdTest extends EcfTestCase
{
    private const string EIN = '832056446';

    public function test_a_customer_with_a_us_address_and_no_type_is_not_dominican(): void
    {
        $customer = $this->customerIn('US');
        $customer->tax_id = self::EIN;
        $customer->save();

        $this->assertSame(TaxIdTypeEnum::US_EIN, $customer->refresh()->tax_id_type);
        $this->assertSame(EcfTypeEnum::CONSUMO, new EcfTypeResolver()->resolve($this->draftInvoice($customer, self::EIN)));
    }

    public function test_an_rnc_dgii_does_not_know_is_refused_before_a_number_is_used(): void
    {
        FakeEcfProvider::$taxpayers = [];
        $customer = $this->customer();
        $invoice = $this->draftInvoice($customer, self::EIN);

        try {
            new IssueInvoiceAction($invoice, $customer, static::$cachedUser)->execute();
            $this->fail('An E31 to a buyer DGII does not know must not be issued.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('DGII has no taxpayer registered under ' . self::EIN, $e->getMessage());
        }

        $this->assertSame(InvoiceDocumentStatusEnum::DRAFT, $invoice->refresh()->document_status);
        $this->assertSame([], FakeEcfProvider::$submitted);
    }

    public function test_submitting_for_approval_asks_dgii_first(): void
    {
        FakeEcfProvider::$taxpayers = [];

        $this->expectException(ValidationException::class);

        new SubmitInvoiceForApprovalAction($this->draftInvoice($this->customer(), self::EIN), static::$cachedUser)->execute();
    }

    /**
     * A US customer with no tax id on file was invoiced 4,160 USD as E32; DGII takes an anonymous E32 only under
     * DOP 250,000, so the approval would have failed after a person signed it.
     */
    public function test_an_e32_over_the_anonymous_limit_with_no_buyer_tax_id_is_refused_at_submit(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');

        try {
            $customer = $this->customer('Harbor Coffee Roasters LLC');
            $big = $this->usdDraft($customer, 5000.0);
            $small = $this->usdDraft($customer, 1000.0);

            $ecf = new EcfIssuanceService();
            $ecf->assertReadyToIssue($small);
            $this->addToAssertionCount(1);

            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('over the DOP 250,000 limit for a consumo invoice (E32) with no buyer tax id');
            $ecf->assertReadyToIssue($big);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function usdDraft(Organization $customer, float $price): Invoice
    {
        $invoice = $this->draftInvoice($customer, null, [[$price, 0.0]]);
        $invoice->currency = 'USD';
        $invoice->saveQuietly();

        return $invoice->refresh();
    }

    public function test_a_registered_rnc_still_issues_credito_fiscal(): void
    {
        FakeEcfProvider::$taxpayers = [self::RNC => 'CLIENTE RNC SRL'];
        $customer = $this->customer();

        $issued = new IssueInvoiceAction($this->draftInvoice($customer), $customer, static::$cachedUser)->execute();

        $this->assertStringStartsWith('E31', (string) $issued->invoice_number);
    }

    private function customerIn(string $countryCode): Organization
    {
        $customer = $this->customer('Too Corporation Americas LLC');
        Address::create([
            'organizations_id' => $customer->getId(),
            'address' => '1 Main St',
            'countries_id' => Countries::query()->where('code', $countryCode)->value('id'),
            'is_default' => true,
            'is_deleted' => false,
        ]);

        return $customer;
    }
}
