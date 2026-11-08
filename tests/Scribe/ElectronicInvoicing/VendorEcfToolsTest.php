<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Alanube\Enums\AccountModeEnum;
use Kanvas\Connectors\Alanube\Enums\ConfigurationEnum as AlanubeConfigurationEnum;
use Kanvas\Connectors\WebPos\Enums\ConfigurationEnum as WebPosConfigurationEnum;
use Kanvas\Connectors\WebPos\Enums\EnvironmentEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\EcfStatusTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\RespondToVendorEcfTool;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Scribe\Bills\Actions\CreateBillFromReceivedEcfAction;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Actions\RespondToReceivedEcfAction;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfEnvironmentEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfProviderEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Exceptions\FiscalNumberHeldElsewhereException;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\PollEcfResultJob;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\ReceivedEcfXmlParserService;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfQrCode;
use Kanvas\Scribe\ElectronicInvoicing\Support\ReceivedEcfCompliance;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Models\Invoice;
use RuntimeException;
use Tests\Scribe\AccountingOperationsTestCase;
use Tests\Stubs\Scribe\FakeEcfProvider;
use Tests\Stubs\Scribe\FakeRespondingEcfProvider;

/**
 * Track J: the provider-neutral commercial response (respond_to_vendor_ecf), ecf_status, and the two pieces
 * consolidated into Scribe (the environment rule and the DGII QR reader).
 */
final class VendorEcfToolsTest extends AccountingOperationsTestCase
{
    protected function afterScribeSetUp(): void
    {
        parent::afterScribeSetUp();
        FakeRespondingEcfProvider::reset();
        config()->set('scribe.ecf_providers.fake_responding', FakeRespondingEcfProvider::class);
    }

    public function testTheActionAsksTheCompanysProviderAndRecordsTheAnswerOnce(): void
    {
        $this->company->set(ConfigurationEnum::ECF_PROVIDER->value, 'fake_responding');
        $bill = $this->vendorEcfBill();

        $response = new RespondToReceivedEcfAction(bill: $bill, accepted: true, user: static::$cachedUser)->execute();

        $this->assertSame([['bill_id' => $bill->getId(), 'accepted' => true, 'reason' => null]], FakeRespondingEcfProvider::$responses);
        $this->assertTrue($response['accepted']);
        $this->assertSame('fake_responding', $response['provider']);
        $this->assertSame('fake-response-1', $response['provider_ref']);
        $this->assertSame(static::$cachedUser->getId(), $response['responded_by_users_id']);
        $this->assertNotNull(ReceivedEcfCompliance::commercialResponse($bill->refresh()));
        $this->assertTrue(Event::query()
            ->where('event_type', 'scribe.bill.ecf_commercial_response_sent')
            ->where('source_entity_id', $bill->getId())
            ->exists());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already answered');
        new RespondToReceivedEcfAction(bill: $bill->refresh(), accepted: false, reason: 'late')->execute();
    }

    public function testRejectingNeedsAReasonAndAPlainBillHasNothingToAnswer(): void
    {
        $this->company->set(ConfigurationEnum::ECF_PROVIDER->value, 'fake_responding');

        try {
            new RespondToReceivedEcfAction(bill: $this->vendorEcfBill(), accepted: false, reason: '  ')->execute();
            $this->fail('A rejection without a reason was sent.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('requires a reason', $e->getMessage());
        }

        $plain = $this->receivedBill('Plain', 10.00);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('did not come in as a received e-CF');
        new RespondToReceivedEcfAction(bill: $plain, accepted: true)->execute();
    }

    public function testAProviderThatCannotAnswerIsRefusedClearly(): void
    {
        $this->company->set(ConfigurationEnum::ECF_PROVIDER->value, 'fake');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot send commercial responses');
        new RespondToReceivedEcfAction(bill: $this->vendorEcfBill(), accepted: true)->execute();
    }

    public function testTheToolRoutesThroughTheProviderAndReadsAsAFailureWhenRefused(): void
    {
        $this->company->set(ConfigurationEnum::ECF_PROVIDER->value, 'fake_responding');
        $bill = $this->vendorEcfBill();

        $rejected = $this->respondTool()->__invoke(bill_id: $bill->getId(), decision: 'reject', reason: 'Precio distinto');
        $again = $this->respondTool()->__invoke(bill_id: $bill->getId(), decision: 'accept');
        $bad = $this->respondTool()->__invoke(bill_id: $bill->getId(), decision: 'maybe');
        $foreign = $this->respondTool()->__invoke(bill_id: 999999999, decision: 'accept');

        $this->assertTrue($rejected['success']);
        $this->assertFalse($rejected['commercial_response']['accepted']);
        $this->assertSame('Precio distinto', FakeRespondingEcfProvider::$responses[0]['reason']);
        $this->assertFalse($again['success']);
        $this->assertFalse($again['sent']);
        $this->assertFalse($bad['success']);
        $this->assertFalse($foreign['success']);
        $this->assertCount(1, FakeRespondingEcfProvider::$responses);
    }

    public function testWebPosAnswersThroughItsOwnReceptionEndpoint(): void
    {
        $this->company->set(ConfigurationEnum::ECF_PROVIDER->value, EcfProviderEnum::WEBPOS->value);
        $this->company->setEncrypted(WebPosConfigurationEnum::API_KEY->value, '00000000-0000-4000-8000-00000000test');
        $this->company->set(WebPosConfigurationEnum::COMPANY_LICENSE->value, 'TESTLIC1');
        Http::fake([
            'https://fedotest-api02.webposonline.com/api/fedo/ak/v1/sendRecEvent/*' => Http::response(['dgiSts' => 1, 'vendorSts' => 1]),
        ]);
        $bill = $this->vendorEcfBill();

        $response = new RespondToReceivedEcfAction(bill: $bill, accepted: true)->execute();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'sendRecEvent/TESTLIC1') && $request['event'] === 2);
        $this->assertSame(2, $response['event']);
        $this->assertSame(1, $response['dgi_status']);
        $this->assertTrue($response['accepted']);
    }

    public function testAlanubeAnswersByVendorRncAndEncf(): void
    {
        $this->company->set(ConfigurationEnum::ECF_PROVIDER->value, EcfProviderEnum::ALANUBE->value);
        $this->company->set(AlanubeConfigurationEnum::ACCOUNT_MODE->value, AccountModeEnum::OWN->value);
        $this->company->setEncrypted(AlanubeConfigurationEnum::API_TOKEN->value, 'own-token-not-real');
        $bill = $this->vendorEcfBill();
        $encf = ReceivedEcfCompliance::encf($bill);
        Http::fake([
            'https://sandbox.alanube.co/dom/v1/received-documents?*' => Http::response(['documents' => [
                ['id' => '01RECEIVED', 'documentNumber' => $encf],
            ]]),
            'https://sandbox.alanube.co/dom/v1/received-documents/01RECEIVED/commercial-response' => Http::response(['commercialApprovalInfo' => ['id' => '01APPROVAL']]),
        ]);

        $response = new RespondToReceivedEcfAction(bill: $bill, accepted: false, reason: 'No es nuestra')->execute();

        $this->assertSame('01APPROVAL', $response['provider_ref']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request['commercialResponse'] === 'REJECTED'
            && $request['notAcceptedDetail'] === 'No es nuestra');
    }

    public function testEcfStatusChecksAWaitingInvoiceWithTheProviderAndReportsAVendorBill(): void
    {
        $this->company->set(ConfigurationEnum::ECF_PROVIDER->value, 'fake');
        $this->company->set(ConfigurationEnum::ECF_ISSUER_TAX_ID->value, '101010101');
        $this->configureRange(
            EcfTypeEnum::CREDITO_FISCAL,
            4,
            1000,
            '2028-12-31'
        );
        $customer = $this->customer();
        $invoice = new IssueInvoiceAction(
            invoice: $this->draftInvoice($customer),
            billable: $customer,
            user: static::$cachedUser,
        )->execute();
        FakeEcfProvider::$fetchStatus = EcfStatusEnum::ACCEPTED;

        $issued = $this->statusTool()->__invoke(invoice_id: $invoice->getId());

        $this->assertTrue($issued['success']);
        $this->assertTrue($issued['checked_with_provider']);
        $this->assertSame('accepted', $issued['status']);
        $this->assertSame('E310000000004', $issued['encf']);
        $this->assertCount(1, FakeEcfProvider::$fetched);

        $again = $this->statusTool()->__invoke(invoice_id: $invoice->getId());
        $this->assertFalse($again['checked_with_provider']);
        $this->assertCount(1, FakeEcfProvider::$fetched);

        $bill = $this->vendorEcfBill();
        $received = $this->statusTool()->__invoke(bill_id: $bill->getId());
        $this->assertTrue($received['is_ecf']);
        $this->assertFalse($received['answered']);
        $this->assertSame('101000009', $received['vendor_rnc']);

        $plain = $this->statusTool()->__invoke(bill_id: $this->receivedBill('Plain', 5.00)->getId());
        $this->assertFalse($plain['is_ecf']);
        $this->assertFalse($this->statusTool()->__invoke()['success']);
    }

    public function testANumberHeldByAnotherDocumentIsFlaggedNotReportedAsUnreachable(): void
    {
        $invoice = $this->issuedFakeEcfInvoice();
        FakeEcfProvider::$fetchStatus = EcfStatusEnum::WAITING;
        FakeEcfProvider::$fetchException = new FiscalNumberHeldElsewhereException('e-NCF E310000000004 is held at the provider by another document.');

        $first = $this->statusTool()->__invoke(invoice_id: $invoice->getId());

        $this->assertTrue($first['success']);
        $this->assertSame('number_used_elsewhere', $first['status']);
        $this->assertStringContainsString('held at the provider by another document', (string) $first['provider_error']);
        $this->assertStringNotContainsString('could not be reached', (string) $first['provider_error']);
        $this->assertStringContainsString('issued again under a new number', (string) $first['note']);

        $fiscal = EcfCompliance::current($invoice);
        $this->assertTrue($fiscal->needs_review);
        $this->assertSame(FiscalDocument::NUMBER_USED_ELSEWHERE, $fiscal->authorityValue('action_required'));

        $fetches = count(FakeEcfProvider::$fetched);
        $again = $this->statusTool()->__invoke(invoice_id: $invoice->getId());
        $this->assertSame('number_used_elsewhere', $again['status']);
        $this->assertCount($fetches, FakeEcfProvider::$fetched, 'a flagged number is not asked about again');
    }

    public function testAProviderAnswerThatIsNotAResultIsPassedThroughAndAnOutageIsNot(): void
    {
        $invoice = $this->issuedFakeEcfInvoice();
        FakeEcfProvider::$fetchException = new ValidationException('The provider has no record of e-CF E310000000004.');

        $noRecord = $this->statusTool()->__invoke(invoice_id: $invoice->getId());
        $this->assertSame('The provider has no record of e-CF E310000000004.', $noRecord['provider_error']);

        FakeEcfProvider::$fetchException = new RuntimeException('connection refused');

        $outage = $this->statusTool()->__invoke(invoice_id: $invoice->getId());
        $this->assertStringContainsString('could not be reached', (string) $outage['provider_error']);
        $this->assertStringNotContainsString('connection refused', (string) $outage['provider_error']);
    }

    public function testThePollerFlagsANumberHeldElsewhereAndStops(): void
    {
        $invoice = $this->issuedFakeEcfInvoice();
        FakeEcfProvider::$fetchException = new FiscalNumberHeldElsewhereException('held elsewhere');
        $fiscal = EcfCompliance::current($invoice);

        new PollEcfResultJob($this->kanvasApp, $fiscal)->handle();

        $fiscal->refresh();
        $this->assertTrue($fiscal->needs_review);
        $this->assertSame(FiscalDocument::NUMBER_USED_ELSEWHERE, $fiscal->authorityValue('action_required'));
        $this->assertTrue($fiscal->status->isOutstanding(), 'nothing was decided at the authority; the row is only flagged');
    }

    public function testAnythingButProductionIsTestForEveryProvider(): void
    {
        $this->assertSame(EcfEnvironmentEnum::TEST, EcfEnvironmentEnum::forCompany($this->company));
        $this->assertSame(EnvironmentEnum::TEST, EnvironmentEnum::forCompany($this->company));

        $this->company->set(ConfigurationEnum::ECF_ENVIRONMENT->value, 'Production ');
        $this->assertSame(EcfEnvironmentEnum::TEST, EcfEnvironmentEnum::forCompany($this->company));

        $this->company->set(ConfigurationEnum::ECF_ENVIRONMENT->value, 'production');
        $this->assertSame(EcfEnvironmentEnum::PRODUCTION, EcfEnvironmentEnum::forCompany($this->company));
        $this->assertSame(EnvironmentEnum::PRODUCTION, EnvironmentEnum::forCompany($this->company));

        $this->company->del(ConfigurationEnum::ECF_ENVIRONMENT->value);
    }

    public function testTheDgiiStampUrlIsReadTheSameWayForEveryProvider(): void
    {
        $this->assertSame(
            ['ENCF' => 'E310000000123', 'CodigoSeguridad' => 'Zz9Yy8'],
            EcfQrCode::params('https://ecf.dgii.gov.do/ConsultaTimbre?ENCF=E310000000123&CodigoSeguridad=Zz9Yy8&Empty=')
        );
        $this->assertSame([], EcfQrCode::params(null));
        $this->assertSame([], EcfQrCode::params('https://ecf.dgii.gov.do/ConsultaTimbre'));
    }

    private function vendorEcfBill(): Bill
    {
        $xml = (string) file_get_contents(base_path('tests/Connectors/WebPos/fixtures/received-e31.xml'));
        $received = new ReceivedEcfXmlParserService()->parse($xml, EcfProviderEnum::WEBPOS);

        return new CreateBillFromReceivedEcfAction(
            app: $this->kanvasApp,
            company: $this->company,
            user: static::$cachedUser,
            received: $received,
        )->execute();
    }

    private function respondTool(): RespondToVendorEcfTool
    {
        return new RespondToVendorEcfTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    /**
     * Left `unknown`, like an invoice whose only send was refused before it reached the authority.
     */
    private function issuedFakeEcfInvoice(): Invoice
    {
        $this->company->set(ConfigurationEnum::ECF_PROVIDER->value, 'fake');
        $this->company->set(ConfigurationEnum::ECF_ISSUER_TAX_ID->value, '101010101');
        $this->configureRange(
            EcfTypeEnum::CREDITO_FISCAL,
            4,
            1000,
            '2028-12-31'
        );
        $customer = $this->customer();
        $invoice = new IssueInvoiceAction(
            invoice: $this->draftInvoice($customer),
            billable: $customer,
            user: static::$cachedUser,
        )->execute();

        $fiscal = EcfCompliance::current($invoice);
        $fiscal->status = FiscalDocumentStatusEnum::UNKNOWN;
        $fiscal->save();

        return $invoice;
    }

    private function statusTool(): EcfStatusTool
    {
        return new EcfStatusTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }
}
