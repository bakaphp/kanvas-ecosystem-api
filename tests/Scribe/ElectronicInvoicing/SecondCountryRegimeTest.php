<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Bills\Actions\CreateBillFromReceivedEcfAction;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\DocumentSequences\Models\DocumentSequence;
use Kanvas\Scribe\ElectronicInvoicing\Actions\ConfigureEcfRangeAction;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\SubmitEcfJob;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalNumberRange;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfIssuanceService;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\ElectronicInvoicing\Support\ReceivedEcfCompliance;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Tests\Stubs\Scribe\FakeEcfProvider;
use Tests\Stubs\Scribe\Regime\FakeFiscalRegime;
use Tests\Stubs\Scribe\Regime\FakePayload;
use Tests\Stubs\Scribe\Regime\FakeReceivedDocument;
use Tests\Stubs\Scribe\Regime\FakeReceivedLine;

/**
 * The shared issuing flow driven by a regime that is not the DR: if this passes, nothing between the issue action
 * and the provider assumes DGII's types, series, number width or payload.
 */
class SecondCountryRegimeTest extends EcfTestCase
{
    protected function companyIssuesEcf(): bool
    {
        return false;
    }

    protected function afterScribeSetUp(): void
    {
        parent::afterScribeSetUp();

        config()->set('scribe.fiscal_regimes.' . FakeFiscalRegime::COUNTRY, FakeFiscalRegime::class);
        FakeEcfProvider::$country = FakeFiscalRegime::COUNTRY;
        FakeFiscalRegime::$checks = [];
        $this->company->set(ConfigurationEnum::ECF_PROVIDER->value, 'fake');
    }

    /**
     * The fake provider's country is static, and later tests in this process expect a Dominican one.
     */
    protected function tearDown(): void
    {
        FakeEcfProvider::reset();
        FakeFiscalRegime::$checks = [];

        parent::tearDown();
    }

    public function test_a_range_is_loaded_in_the_regimes_own_numbering(): void
    {
        $sequence = $this->loadRange('31');

        $this->assertSame('ZZ31-', $sequence->prefix);
        $this->assertSame(1, (int) $sequence->next_value);
        $this->assertSame(
            FakeFiscalRegime::COUNTRY,
            FiscalNumberRange::query()->whereKey($sequence->fiscal_number_range_id)->value('country')
        );
    }

    public function test_an_unknown_type_lists_the_regimes_own_codes(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Use one of 31, 33, 34');

        new ConfigureEcfRangeAction(
            app: $this->kanvasApp,
            company: $this->company,
            type: '99',
            start: '1',
            end: '10',
        )->execute();
    }

    public function test_an_invoice_is_issued_and_sent_through_the_regime(): void
    {
        $this->loadRange('31');
        $invoice = $this->usdInvoice();

        $issued = new IssueInvoiceAction($invoice, $invoice->customer, static::$cachedUser)->execute();
        $fiscal = EcfCompliance::current($issued);

        $this->assertSame('ZZ31-000001', $issued->invoice_number);
        $this->assertSame(FakeFiscalRegime::COUNTRY, $fiscal->country);
        $this->assertSame(FakeFiscalRegime::ISSUER_TAX_ID, $fiscal->issuer_tax_id);
        $this->assertSame([['document' => $invoice->getId(), 'type' => '31']], FakeFiscalRegime::$checks);

        new SubmitEcfJob($this->kanvasApp, $fiscal)->handle();

        $this->assertInstanceOf(FakePayload::class, FakeEcfProvider::$submitted[0]);
        $this->assertSame('ZZ31-000001', FakeEcfProvider::$submitted[0]->number());
        $this->assertTrue($fiscal->refresh()->status->isAccepted());
    }

    public function test_a_note_needs_the_regimes_own_note_range(): void
    {
        $this->loadRange('31');
        $invoice = $this->usdInvoice();
        $issued = new IssueInvoiceAction($invoice, $invoice->customer, static::$cachedUser)->execute();

        try {
            new EcfIssuanceService()->assertNoteNumbersAvailable($issued, isDebit: false);
            $this->fail('A note with no range of its own type must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ZZ34-', $e->getMessage());
        }

        $this->loadRange('34');
        new EcfIssuanceService()->assertNoteNumbersAvailable($issued, isDebit: false);
        $this->addToAssertionCount(1);
    }

    public function test_a_vendor_invoice_received_in_the_second_country_becomes_a_bill(): void
    {
        $received = new FakeReceivedDocument('ZZ31-000777', new FakeReceivedLine('Paper stock', 100.0, 0.07));

        $receive = fn (): Bill => new CreateBillFromReceivedEcfAction(
            app: $this->kanvasApp,
            company: $this->company,
            user: static::$cachedUser,
            received: $received,
        )->execute();
        $bill = $receive();
        $again = $receive();

        $this->assertSame($bill->getId(), $again->getId(), 'The vendor id plus number is the idempotency key.');
        $this->assertSame(CreateBillFromReceivedEcfAction::SOURCE, $bill->source);
        $this->assertSame('ZZ31-000777', ReceivedEcfCompliance::encf($bill));
        $this->assertSame(FakeFiscalRegime::COUNTRY, ReceivedEcfCompliance::current($bill)->country);
        $this->assertTrue(ReceivedEcfCompliance::isReceivedEcf($bill));

        $line = $bill->lines()->first();
        $this->assertEqualsWithDelta(0.07, (float) $line->tax_rate, 0.0001);
        $this->assertSame(['zz_vat' => 'standard'], $line->tax_metadata);
    }

    public function test_draft_choices_are_kept_under_the_countrys_own_key(): void
    {
        $invoice = $this->usdInvoice();

        EcfCompliance::writeDraftSettings($invoice, ['type' => '31']);

        $this->assertSame(['zz_ecf' => ['type' => '31']], $invoice->regional_compliance);
        $this->assertSame(['type' => '31'], EcfCompliance::draftSettings($invoice));
    }

    private function loadRange(string $type): DocumentSequence
    {
        return new ConfigureEcfRangeAction(
            $this->kanvasApp,
            $this->company,
            $type,
            "ZZ{$type}-000001",
            '500',
            '2030-12-31'
        )->execute();
    }

    private function usdInvoice(): Invoice
    {
        $invoice = $this->draftInvoice($this->customer(), null);
        $invoice->currency = 'USD';
        $invoice->saveQuietly();

        return $invoice->refresh();
    }
}
