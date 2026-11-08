<?php

declare(strict_types=1);

namespace Tests\Connectors\Alanube;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Alanube\AlanubeEcfProvider;
use Kanvas\Connectors\Alanube\Enums\AccountModeEnum;
use Kanvas\Connectors\Alanube\Enums\DocumentStatusEnum;
use Kanvas\Connectors\Alanube\Exceptions\AlanubeRequestException;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfDocument;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfLine;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfParty;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfTotals;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum as EcfConfigurationEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfEnvironmentEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfItbisRateEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfProviderResolver;
use Kanvas\Scribe\Invoices\Models\Invoice;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Connectors\Traits\HasAlanubeConfiguration;
use Tests\Scribe\ElectronicInvoicing\Concerns\CreatesFiscalDocuments;
use Tests\TestCase;

final class AlanubeEcfProviderTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesFiscalDocuments;
    use HasAlanubeConfiguration;

    private const string ENCF = 'E320000000010';
    private const string DOCUMENT_ID = '01G021Z3QSRZ58GSTBH7TPGD2J';

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'accounting'];

    /**
     * @return array<string, array{0: string, 1: ?string, 2: EcfStatusEnum}>
     */
    public static function statuses(): array
    {
        return [
            'registered' => ['REGISTERED', null, EcfStatusEnum::WAITING],
            'to send' => ['TO_SEND', null, EcfStatusEnum::WAITING],
            'waiting response' => ['WAITING_RESPONSE', null, EcfStatusEnum::WAITING],
            'to notify' => ['TO_NOTIFY', 'ACCEPTED', EcfStatusEnum::WAITING],
            'finished accepted' => ['FINISHED', 'ACCEPTED', EcfStatusEnum::ACCEPTED],
            'finished with observations' => ['FINISHED', 'ACCEPTED_WITH_OBSERVATIONS', EcfStatusEnum::ACCEPTED_WITH_OBSERVATIONS],
            'finished rejected' => ['FINISHED', 'REJECTED', EcfStatusEnum::REJECTED],
            'finished without a legal status' => ['FINISHED', null, EcfStatusEnum::WAITING],
        ];
    }

    #[DataProvider('statuses')]
    public function testEachAlanubeStatusMapsOntoTheCanonicalOne(string $status, ?string $legal, EcfStatusEnum $expected): void
    {
        $this->assertSame($expected, DocumentStatusEnum::toCanonical($status, $legal));
    }

    public function testOwnModeSendsTheTenantTokenAndNoCompanyScope(): void
    {
        $company = $this->alanubeCompany(AccountModeEnum::OWN);
        Http::fake([self::ALANUBE_SANDBOX . 'invoices' => Http::response($this->finished('ACCEPTED'), 201)]);

        $submission = $this->provider($company)->submit($this->document());

        $this->assertSame(EcfStatusEnum::ACCEPTED, $submission->status);
        $this->assertSame(self::DOCUMENT_ID, $submission->providerReference);
        $this->assertSame('MYUVCT', $submission->securityCode);
        $this->assertSame('https://fc.dgii.gov.do/testecf/ConsultaTimbreFC?ENCF=' . self::ENCF, $submission->qrUrl);

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('Authorization', 'Bearer ' . self::ALANUBE_OWN_TOKEN)
                && ! array_key_exists('company', $request->data())
                && $request['idDoc']['encf'] === self::ENCF;
        });
    }

    public function testKanvasModeSendsTheMainTokenAndTheTenantCompanyId(): void
    {
        $company = $this->alanubeCompany(AccountModeEnum::KANVAS);
        Http::fake([self::ALANUBE_SANDBOX . 'invoices' => Http::response($this->waiting(), 201)]);

        $submission = $this->provider($company)->submit($this->document());

        $this->assertSame(EcfStatusEnum::WAITING, $submission->status);

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('Authorization', 'Bearer ' . self::ALANUBE_MAIN_TOKEN)
                && $request['company'] === ['id' => self::ALANUBE_COMPANY_ID];
        });
    }

    public function testKanvasModeScopesReadsWithTheCompanyId(): void
    {
        $company = $this->alanubeCompany(AccountModeEnum::KANVAS);
        $url = self::ALANUBE_SANDBOX . 'invoices/' . self::DOCUMENT_ID . '/idCompany/' . self::ALANUBE_COMPANY_ID;
        Http::fake([$url => Http::response($this->finished('REJECTED', [['codigo' => '2', 'valor' => 'RNC del comprador invalido']]))]);

        $submission = $this->provider($company)->fetchSubmission(EcfTypeEnum::CONSUMO, self::DOCUMENT_ID, self::ENCF);

        $this->assertSame(EcfStatusEnum::REJECTED, $submission->status);
        $this->assertSame(['2 RNC del comprador invalido'], $submission->messages);
    }

    public function testAValidationAnswerIsARejectionTheAgentCanRead(): void
    {
        $company = $this->alanubeCompany();
        Http::fake([self::ALANUBE_SANDBOX . 'invoices' => Http::response(['message' => ['instance.idDoc.encf is required'], 'code' => 'AP0000'], 400)]);

        $submission = $this->provider($company)->submit($this->document());

        $this->assertSame(EcfStatusEnum::REJECTED, $submission->status);
        $this->assertSame(['AP0000: instance.idDoc.encf is required'], $submission->messages);
        $this->assertFalse($submission->reachedAuthority, 'A validation answer never reached DGII, so the e-NCF is not burned.');
    }

    public function testDgiiSequenceConsumedIsPassedThrough(): void
    {
        $this->assertFalse(AlanubeEcfProvider::toSubmission([...$this->finished('REJECTED'), 'sequenceConsumed' => false], self::ENCF)->numberConsumed);
        $this->assertTrue(AlanubeEcfProvider::toSubmission([...$this->finished('REJECTED'), 'sequenceConsumed' => true], self::ENCF)->numberConsumed);
        $this->assertNull(AlanubeEcfProvider::toSubmission($this->finished('REJECTED'), self::ENCF)->numberConsumed);
    }

    public function testAServerErrorIsThrownSoTheSubmitIsRetried(): void
    {
        $company = $this->alanubeCompany();
        Http::fake([self::ALANUBE_SANDBOX . 'invoices' => Http::response(['message' => 'An unexpected error has occurred', 'code' => 'EPR500'], 500)]);

        $this->expectException(AlanubeRequestException::class);

        $this->provider($company)->submit($this->document());
    }

    public function testADgiiTimeoutOnASynchronousE32StaysWaitingWithTheReason(): void
    {
        $company = $this->alanubeCompany();
        Http::fake([self::ALANUBE_SANDBOX . 'invoices' => Http::response([
            'id' => self::DOCUMENT_ID,
            'status' => 'TO_SEND',
            'response' => [['message' => 'The connection with DGII has timed out.', 'code' => 'AEP2006']],
        ], 201)]);

        $submission = $this->provider($company)->submit($this->document());

        $this->assertSame(EcfStatusEnum::WAITING, $submission->status);
        $this->assertSame(['AEP2006 The connection with DGII has timed out.'], $submission->messages);
    }

    public function testARetriedSubmitIsAnsweredFromAlanubeInsteadOfSentTwice(): void
    {
        $company = $this->alanubeCompany();
        $this->invoiceWithReference($company, self::DOCUMENT_ID);
        Http::fake([self::ALANUBE_SANDBOX . 'invoices/' . self::DOCUMENT_ID => Http::response($this->finished('ACCEPTED'))]);

        $submission = $this->provider($company)->submit($this->document());

        $this->assertTrue($submission->wasDuplicated);
        $this->assertSame(EcfStatusEnum::ACCEPTED, $submission->status);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function testARetriedSubmitForABillsEcfIsAnsweredFromAlanubeToo(): void
    {
        $company = $this->alanubeCompany();
        $bill = Bill::query()->create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'currency' => 'DOP',
            'fx_rate_to_base' => 1,
        ]);
        $this->submissionFor(
            $this->fiscalRowFor(
                $bill,
                self::ENCF,
                provider: 'alanube',
                attributes: ['uuid' => $this->document()->systemRef]
            ),
            self::DOCUMENT_ID
        );
        Http::fake([self::ALANUBE_SANDBOX . 'invoices/' . self::DOCUMENT_ID => Http::response($this->finished('ACCEPTED'))]);

        $submission = $this->provider($company)->submit($this->document());

        $this->assertTrue($submission->wasDuplicated);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function testAResendAfterAReusableRejectionIsPostedNotAnsweredWithTheRejectedDocument(): void
    {
        $company = $this->alanubeCompany();
        $invoice = Invoice::query()->create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'currency' => 'DOP',
            'fx_rate_to_base' => 1,
        ]);
        $fiscal = $this->fiscalRowFor($invoice, self::ENCF, provider: 'alanube');
        $this->submissionFor($fiscal, 'rejected-alanube-id');
        $resend = $this->submissionFor($fiscal, null, attempt: 2);
        $resend->uuid = $this->document()->systemRef;
        $resend->save();
        Http::fake([self::ALANUBE_SANDBOX . 'invoices' => Http::response($this->finished('ACCEPTED'), 201)]);

        $submission = $this->provider($company)->submit($this->document());

        $this->assertFalse($submission->wasDuplicated);
        $this->assertSame(EcfStatusEnum::ACCEPTED, $submission->status);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function testAProviderBuiltForARowsEnvironmentIgnoresTheCompanysCurrentOne(): void
    {
        $company = $this->alanubeCompany();
        $company->set(EcfConfigurationEnum::ECF_ENVIRONMENT->value, EcfEnvironmentEnum::PRODUCTION->value);
        Http::fake([self::ALANUBE_SANDBOX . 'invoices' => Http::response($this->finished('ACCEPTED'), 201)]);

        try {
            new AlanubeEcfProvider(app: app(Apps::class), company: $company, environment: EcfEnvironmentEnum::TEST)
                ->submit($this->document());
        } finally {
            $company->del(EcfConfigurationEnum::ECF_ENVIRONMENT->value);
        }

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), self::ALANUBE_SANDBOX));
    }

    public function testFetchResultWithoutAReferenceWaits(): void
    {
        $company = $this->alanubeCompany();
        Http::fake();

        $submission = $this->provider($company)->fetchResult($this->document());

        $this->assertSame(EcfStatusEnum::WAITING, $submission->status);
        Http::assertNothingSent();
    }

    public function testLookupTaxpayerReadsTheDgiiDirectory(): void
    {
        $company = $this->alanubeCompany();
        Http::fake([self::ALANUBE_SANDBOX . 'check-directory*' => Http::response([
            ['nombre' => 'DGII', 'rnc' => '131880738', 'urlRecepcion' => 'https://ecf.dgii.gov.do/testecf/emisorreceptor'],
            ['nombre' => 'Cliente Corporativo SRL', 'rnc' => '101000001'],
        ])]);

        $this->assertSame('Cliente Corporativo SRL', $this->provider($company)->lookupTaxpayer('101-00000-1'));
        $this->assertNull($this->provider($company)->lookupTaxpayer('999999999'));
    }

    public function testTheScribeResolverBuildsTheAlanubeProvider(): void
    {
        $company = $this->alanubeCompany();
        $company->set('scribe-ecf-provider', 'alanube');

        $this->assertInstanceOf(AlanubeEcfProvider::class, new EcfProviderResolver()->resolve(app(Apps::class), $company));
    }

    private function provider(Companies $company): AlanubeEcfProvider
    {
        return new AlanubeEcfProvider(app: app(Apps::class), company: $company);
    }

    private function invoiceWithReference(Companies $company, string $reference): Invoice
    {
        $invoice = Invoice::query()->create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'currency' => 'DOP',
            'fx_rate_to_base' => 1,
        ]);
        $this->submissionFor(
            $this->fiscalRowFor(
                $invoice,
                self::ENCF,
                provider: 'alanube',
                attributes: ['uuid' => $this->document()->systemRef]
            ),
            $reference
        );

        return $invoice;
    }

    /**
     * @param array<int, array{codigo: string, valor: string}> $governmentMessages
     * @return array<string, mixed>
     */
    private function finished(string $legalStatus, array $governmentMessages = []): array
    {
        return [
            'id' => self::DOCUMENT_ID,
            'status' => 'FINISHED',
            'legalStatus' => $legalStatus,
            'documentNumber' => self::ENCF,
            'securityCode' => 'MYUVCT',
            'signatureDate' => '2026-06-15 10:00:00',
            'documentStampUrl' => 'https://fc.dgii.gov.do/testecf/ConsultaTimbreFC?ENCF=' . self::ENCF,
            'governmentResponse' => ['value' => $governmentMessages, 'code' => 1],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function waiting(): array
    {
        return ['id' => self::DOCUMENT_ID, 'status' => 'WAITING_RESPONSE', 'encf' => self::ENCF];
    }

    private function document(): EcfDocument
    {
        return new EcfDocument(
            type: EcfTypeEnum::CONSUMO,
            encf: self::ENCF,
            sequenceExpiresAt: '2028-12-31',
            issueDate: '2026-06-15',
            systemRef: 'c4a2d3e5-0000-4000-8000-0000000000a1',
            buyer: new EcfParty(name: 'Consumidor final'),
            currency: 'DOP',
            exchangeRate: 1.0,
            isCredit: false,
            lines: [
                new EcfLine(
                    lineNumber: 1,
                    code: 'CAM-L',
                    description: 'Camisa negra talla L',
                    quantity: 1.0,
                    unitPrice: 1000.0,
                    itbis: EcfItbisRateEnum::RATE_18,
                    isService: false,
                    discountAmount: 0.0,
                    subtotal: 1000.0,
                    tax: 180.0,
                ),
            ],
            totals: new EcfTotals(
                exempt: 0.0,
                taxed: 1000.0,
                itbis: 180.0,
                total: 1180.0
            ),
        );
    }
}
