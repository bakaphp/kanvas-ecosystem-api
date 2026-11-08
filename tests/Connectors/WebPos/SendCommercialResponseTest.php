<?php

declare(strict_types=1);

namespace Tests\Connectors\WebPos;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\WebPos\Actions\SendCommercialResponseAction;
use Kanvas\Connectors\WebPos\Client;
use Kanvas\Connectors\WebPos\Enums\CommercialResponseEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\CreateBillFromReceivedEcfAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfTotals;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\ReceivedEcf;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\ReceivedEcfLine;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfItbisRateEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Support\ReceivedEcfCompliance;
use Spatie\LaravelData\DataCollection;
use Tests\Connectors\Traits\HasWebPosConfiguration;
use Tests\Scribe\ScribeTestCase;

final class SendCommercialResponseTest extends ScribeTestCase
{
    use HasWebPosConfiguration;

    private const string VENDOR_RNC = '101000009';
    private const string ENCF = 'E310000000456';

    private Client $client;

    protected function afterScribeSetUp(): void
    {
        $this->client = new Client($this->webPosCompany());

        Http::fake([
            self::WEBPOS_TEST_RECEPTION_HOST . '/api/fedo/ak/v1/sendRecEvent/*' => Http::response(['dgiSts' => 1, 'vendorSts' => 1]),
        ]);
    }

    public function testApprovalSendsEvent2ForTheVendorDocument(): void
    {
        $bill = $this->receivedBill();

        new SendCommercialResponseAction(bill: $bill, response: CommercialResponseEnum::APPROVE, client: $this->client)->execute();

        Http::assertSent(fn (Request $request): bool => $request->url() === self::WEBPOS_TEST_RECEPTION_HOST . '/api/fedo/ak/v1/sendRecEvent/' . self::WEBPOS_LICENSE . '/' . self::WEBPOS_API_KEY
            && $request->data() === ['vendorRNC' => self::VENDOR_RNC, 'eCF' => self::ENCF, 'event' => 2]);
        $this->assertSame(2, ReceivedEcfCompliance::commercialResponse($bill)['event']);
    }

    public function testRejectionSendsEvent4WithTheReason(): void
    {
        $bill = $this->receivedBill();

        new SendCommercialResponseAction(
            bill: $bill,
            response: CommercialResponseEnum::REJECT,
            reason: 'Precio distinto a la orden de compra',
            client: $this->client,
        )->execute();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'sendRecEvent')
            && $request['event'] === 4
            && $request['motivoRechazo'] === 'Precio distinto a la orden de compra');
    }

    public function testRejectingWithoutAReasonIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('requires a reason');

        new SendCommercialResponseAction(bill: $this->receivedBill(), response: CommercialResponseEnum::REJECT, client: $this->client)->execute();
    }

    public function testABillThatDidNotComeInAsAnEcfHasNothingToAnswer(): void
    {
        $bill = new CreateBillAction(
            data: new BillData(
                app: $this->kanvasApp,
                company: $this->company,
                vendor: null,
                lines: new DataCollection(BillLineData::class, [new BillLineData(description: 'Manual', quantity: 1, unit_price_native: 10.0)]),
                currency: 'DOP',
                fx_rate_to_base: 1.0,
            ),
            user: static::$cachedUser,
        )->execute();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('did not come in as a received e-CF');

        new SendCommercialResponseAction(bill: $bill, response: CommercialResponseEnum::APPROVE, client: $this->client)->execute();
    }

    private function receivedBill(): Bill
    {
        return new CreateBillFromReceivedEcfAction(
            app: $this->kanvasApp,
            company: $this->company,
            user: static::$cachedUser,
            received: new ReceivedEcf(
                vendorTaxId: self::VENDOR_RNC,
                vendorName: 'Proveedora Ejemplo SRL',
                encf: self::ENCF,
                type: EcfTypeEnum::CREDITO_FISCAL,
                issueDate: Carbon::today()->toDateString(),
                currency: 'DOP',
                exchangeRate: 1.0,
                totals: new EcfTotals(
                    exempt: 0.0,
                    taxed: 100.0,
                    itbis: 18.0,
                    total: 118.0
                ),
                lines: [
                    new ReceivedEcfLine(
                        lineNumber: 1,
                        description: 'Servicio',
                        quantity: 1,
                        unitPrice: 100.0,
                        discountAmount: 0.0,
                        amount: 100.0,
                        itbis: EcfItbisRateEnum::RATE_18,
                        tax: 18.0,
                        isService: true,
                    ),
                ],
                provider: 'webpos',
            ),
        )->execute();
    }
}
