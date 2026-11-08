<?php

declare(strict_types=1);

namespace Tests\Connectors\WebPos;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WebPos\Providers\WebPosEcfProvider;
use Kanvas\Connectors\WebPos\Services\ResultMapperService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfDocument;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfLine;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfParty;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfTotals;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfItbisRateEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Exceptions\ProviderRejectedException;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Connectors\Traits\HasWebPosConfiguration;
use Tests\TestCase;

final class WebPosEcfProviderTest extends TestCase
{
    use DatabaseTransactions;
    use HasWebPosConfiguration;

    private const string ENCF = 'E310000000004';
    private const string SYSTEM_REF = 'b3f1c2d4-0000-4000-8000-000000000031';
    private const string QR = 'https://fc.dgii.gov.do/testecf/ConsultaTimbre?RncEmisor=130000001&RncComprador=101000001'
        . '&ENCF=E310000000004&FechaEmision=15-06-2026&MontoTotal=354.00&FechaFirma=15-06-2026%2010:00:00&CodigoSeguridad=Ab12Cd';

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    private Companies $webPosCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->webPosCompany = $this->webPosCompany();
    }

    /**
     * @return array<string, array{0: int, 1: EcfStatusEnum}>
     */
    public static function statuses(): array
    {
        return [
            '1 pending' => [1, EcfStatusEnum::WAITING],
            '2 authorized' => [2, EcfStatusEnum::ACCEPTED],
            '3 in process' => [3, EcfStatusEnum::WAITING],
            '4 rejected' => [4, EcfStatusEnum::REJECTED],
            '8 conditionally accepted' => [8, EcfStatusEnum::ACCEPTED_WITH_OBSERVATIONS],
            '9 checking' => [9, EcfStatusEnum::WAITING],
        ];
    }

    #[DataProvider('statuses')]
    public function testEachWebPosStatusMapsOntoTheCanonicalOne(int $webPosStatus, EcfStatusEnum $expected): void
    {
        $this->fakeSubmit(['received' => true, 'accepted' => true, 'status' => $webPosStatus, 'feNumber' => self::ENCF]);

        $submission = $this->provider()->submit($this->document());

        $this->assertSame($expected, $submission->status);
        $this->assertSame(self::ENCF, $submission->encf);
    }

    public function testAnAcceptedResultCarriesTrackIdQrLegendsSecurityCodeAndPdf(): void
    {
        $this->fakeSubmit([
            'received' => true,
            'accepted' => true,
            'status' => 2,
            'feNumber' => self::ENCF,
            'authNumber' => 'track-0001',
            'confirmationNbr' => '998877',
            'qrContent' => self::QR,
            'qrL1' => 'Código de Seguridad: Ab12Cd',
            'qrL2' => 'Fecha de Firma: 15-06-2026 10:00:00',
            'pdf' => base64_encode('%PDF-1.4 fake'),
        ]);

        $submission = $this->provider()->submit($this->document());

        $this->assertSame('track-0001', $submission->providerReference);
        $this->assertSame('Ab12Cd', $submission->securityCode, 'The security code comes from the QR, not confirmationNbr.');
        $this->assertSame('15-06-2026 10:00:00', $submission->signedAt);
        $this->assertSame(self::QR, $submission->qrUrl);
        $this->assertSame(['Código de Seguridad: Ab12Cd', 'Fecha de Firma: 15-06-2026 10:00:00'], $submission->qrLegends);
        $this->assertSame(base64_encode('%PDF-1.4 fake'), $submission->pdfBase64);
        $this->assertFalse($submission->wasDuplicated);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::WEBPOS_TEST_HOST . '/api/fedo/ak/v1/test/sendFileToProcess/' . self::WEBPOS_LICENSE . '/' . self::WEBPOS_API_KEY
                && $request->isJson()
                && $request['fiscalDoc']['systemRef'] === self::SYSTEM_REF
                && $request['fiscalDoc']['docNumber'] === self::ENCF
                && $request['fiscalDoc']['branchCod'] === '0001'
                && $request['fiscalDoc']['posCod'] === '001';
        });
    }

    public function testADuplicatedSystemRefIsReportedNotReissued(): void
    {
        $this->fakeSubmit(['received' => true, 'accepted' => true, 'status' => 2, 'feNumber' => self::ENCF, 'wasDuplicated' => true]);

        $submission = $this->provider()->submit($this->document());

        $this->assertTrue($submission->wasDuplicated);
        $this->assertSame(EcfStatusEnum::ACCEPTED, $submission->status);
    }

    public function testARejectionCarriesWebPosAndDgiMessages(): void
    {
        $this->fakeSubmit([
            'received' => true,
            'accepted' => true,
            'status' => 4,
            'feNumber' => self::ENCF,
            'msg' => 'Documento rechazado por DGII',
            'dgiErrMsg' => 'RNC del comprador no válido',
            'dgiResp' => json_encode([
                'estado' => 'Rechazado',
                'mensajes' => [['valor' => 'El RNC del comprador no existe', 'codigo' => 1015]],
            ]),
        ]);

        $submission = $this->provider()->submit($this->document());

        $this->assertSame(EcfStatusEnum::REJECTED, $submission->status);
        $this->assertSame([
            'Documento rechazado por DGII',
            'RNC del comprador no válido',
            '[1015] El RNC del comprador no existe',
        ], $submission->messages);
        $this->assertNull($submission->numberConsumed, 'DGII did not say whether the number was used.');
        $this->assertSame(4, $submission->rawResponse['status']);
    }

    public function testDgiiSecuenciaUtilizadaIsPassedThrough(): void
    {
        foreach ([false, true] as $used) {
            $submission = ResultMapperService::toSubmission([
                'status' => 4,
                'feNumber' => self::ENCF,
                'dgiResp' => json_encode(['estado' => 'Rechazado', 'secuenciaUtilizada' => $used]),
            ]);

            $this->assertSame($used, $submission->numberConsumed);
        }
    }

    public function testTheWirePayloadIsWhatSubmitSends(): void
    {
        $this->fakeSubmit(['received' => true, 'accepted' => true, 'status' => 2, 'feNumber' => self::ENCF]);
        $provider = $this->provider();

        $provider->submit($this->document());

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->data() === $provider->requestPayload($this->document()));
    }

    public function testAJsonWebPosCouldNotTransformIsAnErrorNotARejection(): void
    {
        $this->fakeSubmit(['received' => true, 'accepted' => false, 'msg' => 'Campo docDate inválido']);

        $this->expectException(ProviderRejectedException::class);
        $this->expectExceptionMessage('Campo docDate inválido');

        $this->provider()->submit($this->document());
    }

    public function testFetchResultReadsOurAttemptBySystemRef(): void
    {
        Http::fake([
            self::WEBPOS_TEST_HOST . '/api/fedo/ak/v1/geteCfBySystemRef/*' => Http::response([
                'status' => 3,
                'feNumber' => self::ENCF,
                'systemRef' => self::SYSTEM_REF,
            ]),
        ]);

        $submission = $this->provider()->fetchResult($this->document());

        $this->assertSame(EcfStatusEnum::WAITING, $submission->status);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::WEBPOS_TEST_HOST
            . '/api/fedo/ak/v1/geteCfBySystemRef/' . self::WEBPOS_LICENSE . '/' . self::WEBPOS_API_KEY . '/2/31/' . self::SYSTEM_REF);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'GetResultFe'));
    }

    public function testFetchResultFallsBackToGetResultFeV1ByNumber(): void
    {
        Http::fake([
            self::WEBPOS_TEST_HOST . '/api/fedo/ak/v1/geteCfBySystemRef/*' => Http::response([
                'feNumber' => '',
                'systemRef' => self::SYSTEM_REF,
            ]),
            self::WEBPOS_TEST_HOST . '/api/fedo/ak/v1/test/getResultFe/' . self::WEBPOS_LICENSE . '/' . self::WEBPOS_API_KEY . '/' . self::ENCF => Http::response([
                'sts' => 8,
                'feNumber' => self::ENCF,
                'systemRef' => self::SYSTEM_REF,
                'msg' => 'Aceptado condicional',
            ]),
        ]);

        $submission = $this->provider()->fetchResult($this->document());

        $this->assertSame(EcfStatusEnum::ACCEPTED_WITH_OBSERVATIONS, $submission->status);
        $this->assertSame(['Aceptado condicional'], $submission->messages);
    }

    public function testANumberHeldByAnotherDocumentIsNeverTakenAsOurs(): void
    {
        Http::fake([
            self::WEBPOS_TEST_HOST . '/api/fedo/ak/v1/geteCfBySystemRef/*' => Http::response(null),
            self::WEBPOS_TEST_HOST . '/api/fedo/ak/v1/test/getResultFe/*' => Http::response([
                'sts' => 2,
                'dgiSts' => 2,
                'feNumber' => self::ENCF,
                'systemRef' => self::ENCF,
            ]),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('held at WebPOS by another document');

        $this->provider()->fetchResult($this->document());
    }

    public function testFetchResultWithNoRecordAnywhereThrows(): void
    {
        Http::fake([
            self::WEBPOS_TEST_HOST . '/api/fedo/ak/v1/geteCfBySystemRef/*' => Http::response(['feNumber' => '', 'systemRef' => self::SYSTEM_REF]),
            self::WEBPOS_TEST_HOST . '/api/fedo/ak/v1/test/getResultFe/*' => Http::response(null),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('has no record');

        $this->provider()->fetchResult($this->document());
    }

    public function testIsNumberIssuedReadsGetResultFeV1(): void
    {
        Http::fake([
            self::WEBPOS_TEST_HOST . '/api/fedo/ak/v1/test/getResultFe/' . self::WEBPOS_LICENSE . '/' . self::WEBPOS_API_KEY . '/E310000000004' => Http::response([
                'feNumber' => 'E310000000004',
                'dgiStatus' => 'AUTORIZADO',
            ]),
            self::WEBPOS_TEST_HOST . '/api/fedo/ak/v1/test/getResultFe/' . self::WEBPOS_LICENSE . '/' . self::WEBPOS_API_KEY . '/E310000000005' => Http::response(null),
        ]);

        $this->assertTrue($this->provider()->isNumberIssued(EcfTypeEnum::CREDITO_FISCAL, 'E310000000004'));
        $this->assertFalse($this->provider()->isNumberIssued(EcfTypeEnum::CREDITO_FISCAL, 'E310000000005'));
    }

    public function testANoPermissionRefusalOnSubmitIsAProviderRejection(): void
    {
        Http::fake([
            self::WEBPOS_TEST_HOST . '/api/fedo/ak/v1/test/sendFileToProcess/*' => Http::response(
                ['message' => '[{"dCodRes":"E000","dMsgRes":"No tiene permisos"}]'],
                400
            ),
        ]);

        $this->expectException(ProviderRejectedException::class);
        $this->expectExceptionMessage('no permission to issue e-CF');

        $this->provider()->submit($this->document());
    }

    public function testLookupTaxpayerReturnsTheLegalNameOrNull(): void
    {
        Http::fake([
            self::WEBPOS_TEST_HOST . '/api/fedo/ak/v1/checkRnc/' . self::WEBPOS_API_KEY . '/101000001' => Http::response(['name' => 'Proveedora Ejemplo SRL']),
            self::WEBPOS_TEST_HOST . '/api/fedo/ak/v1/checkRnc/' . self::WEBPOS_API_KEY . '/999999999' => Http::response([], 404),
        ]);

        $this->assertSame('Proveedora Ejemplo SRL', $this->provider()->lookupTaxpayer('1-01-00000-1'));
        $this->assertNull($this->provider()->lookupTaxpayer('999999999'));
    }

    public function testTheResolverShapeBuildsTheRealClient(): void
    {
        Http::fake([
            self::WEBPOS_TEST_HOST . '/api/fedo/ak/v1/checkRnc/*' => Http::response(['razonSocial' => 'Ejemplo SRL']),
        ]);

        $provider = new WebPosEcfProvider(app: app(Apps::class), company: $this->webPosCompany);

        $this->assertSame('Ejemplo SRL', $provider->lookupTaxpayer('101000001'));
    }

    /**
     * @param array<string, mixed> $response
     */
    private function fakeSubmit(array $response): void
    {
        Http::fake([
            self::WEBPOS_TEST_HOST . '/api/fedo/ak/v1/test/sendFileToProcess/*' => Http::response($response),
        ]);
    }

    private function provider(): WebPosEcfProvider
    {
        return new WebPosEcfProvider(app: app(Apps::class), company: $this->webPosCompany);
    }

    private function document(): EcfDocument
    {
        return new EcfDocument(
            type: EcfTypeEnum::CREDITO_FISCAL,
            encf: self::ENCF,
            sequenceExpiresAt: '2028-12-31',
            issueDate: '2026-06-15',
            systemRef: self::SYSTEM_REF,
            buyer: new EcfParty(name: 'Cliente Ejemplo SRL', taxId: '101000001'),
            currency: 'DOP',
            exchangeRate: 1.0,
            isCredit: true,
            lines: [
                new EcfLine(
                    lineNumber: 1,
                    code: 'SVC-1',
                    description: 'Servicio',
                    quantity: 1,
                    unitPrice: 300.0,
                    itbis: EcfItbisRateEnum::RATE_18,
                    isService: true,
                    discountAmount: 0.0,
                    subtotal: 300.0,
                    tax: 54.0,
                ),
            ],
            totals: new EcfTotals(
                exempt: 0.0,
                taxed: 300.0,
                itbis: 54.0,
                total: 354.0
            ),
        );
    }
}
