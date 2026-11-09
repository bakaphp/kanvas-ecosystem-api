<?php

declare(strict_types=1);

namespace Tests\Connectors\WebPos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WebPos\Actions\PullReceivedEcfsAction;
use Kanvas\Connectors\WebPos\Client;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Actions\CreateBillFromReceivedEcfAction;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDirectionEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Support\ReceivedEcfCompliance;
use Tests\Connectors\Traits\HasWebPosConfiguration;
use Tests\Scribe\ScribeTestCase;

final class PullReceivedEcfsTest extends ScribeTestCase
{
    use HasWebPosConfiguration;

    private const string VENDOR_RNC = '101000009';
    private const string ENCF = 'E310000000123';

    private Companies $webPosCompany;

    protected function afterScribeSetUp(): void
    {
        $this->webPosCompany = $this->webPosCompany();
    }

    public function testAReceivedEcfBecomesADraftBillAndIsMarkedDownloadedOnlyAfterTheBillIsSaved(): void
    {
        $billExistedWhenMarked = null;

        $this->fakeReception(function () use (&$billExistedWhenMarked) {
            $billExistedWhenMarked = $this->receivedBills()->exists();

            return Http::response(['ok' => true]);
        });

        $result = $this->pull();

        $this->assertSame(['created' => 1, 'failed' => 0, 'pages' => 1], $result);
        $this->assertTrue($billExistedWhenMarked, 'updRecDocDownload must run after the bill is saved.');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/fedo/ak/v1/updRecDocPaging/' . self::WEBPOS_LICENSE . '/' . self::WEBPOS_API_KEY . '/pg-1'));

        $bill = $this->receivedBills()->with('lines')->sole();
        $this->assertSame(BillDocumentStatusEnum::DRAFT, $bill->document_status);
        $this->assertSame(self::ENCF, $bill->bill_number);
        $this->assertSame('DOP', $bill->currency);
        $this->assertEqualsWithDelta(1970.00, (float) $bill->total_native, 0.001);
        $this->assertEqualsWithDelta(270.00, (float) $bill->tax_native, 0.001);
        $this->assertCount(4, $bill->lines);
        $received = ReceivedEcfCompliance::current($bill);
        $this->assertSame(self::ENCF, $received->number);
        $this->assertSame(FiscalDirectionEnum::RECEIVED, $received->direction);
        $this->assertSame(FiscalDocumentStatusEnum::ACCEPTED, $received->status);

        $vendor = Organization::query()->where('id', $bill->vendor_organization_id)->sole();
        $this->assertSame('Proveedora Ejemplo SRL', $vendor->name);
        $this->assertSame(self::VENDOR_RNC, $vendor->getPayeeTaxId());
    }

    public function testPullingTheSameDocumentTwiceKeepsOneBill(): void
    {
        $this->fakeReception(fn () => Http::response(['ok' => true]), pagingId: null);

        $this->pull();
        $this->pull();

        $this->assertSame(1, $this->receivedBills()->count());
    }

    public function testAnExistingVendorIsMatchedByRncNotDuplicated(): void
    {
        $vendor = $this->seedTestOrganization('Nombre Comercial Distinto');
        $vendor->tax_id = self::VENDOR_RNC;
        $vendor->saveOrFail();

        $this->fakeReception(fn () => Http::response(['ok' => true]), pagingId: null);
        $this->pull();

        $this->assertSame($vendor->getId(), $this->receivedBills()->sole()->vendor_organization_id);
    }

    public function testAVendorWithTheSameNameButAnotherRncIsNotUsed(): void
    {
        $other = $this->seedTestOrganization('Proveedora Ejemplo SRL');
        $other->tax_id = '131999999';
        $other->saveOrFail();

        $this->fakeReception(fn () => Http::response(['ok' => true]), pagingId: null);
        $this->pull();

        $bill = $this->receivedBills()->sole();
        $this->assertNull($bill->vendor_organization_id);
        $this->assertSame(self::VENDOR_RNC, $bill->vendor_tax_id);
    }

    public function testADocumentThatCannotBecomeABillIsNeitherMarkedNorItsPageAcknowledged(): void
    {
        Http::fake([
            self::WEBPOS_TEST_RECEPTION_HOST . '/api/fedo/ak/v1/getRecDoc/*' => Http::response($this->recDocPage('pg-1')),
            self::WEBPOS_TEST_RECEPTION_HOST . '/api/fedo/ak/v1/getRecXml/*' => Http::response(['xml' => base64_encode('<ECF><broken>')]),
            '*' => Http::response(['ok' => true]),
        ]);

        $result = $this->pull();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $this->receivedBills()->count());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'updRecDocDownload'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'updRecDocPaging'));
    }

    private function pull(): array
    {
        return new PullReceivedEcfsAction(
            app: $this->kanvasApp,
            company: $this->company,
            user: static::$cachedUser,
            client: new Client($this->webPosCompany),
        )->execute();
    }

    private function fakeReception(callable $onMarkDownloaded, ?string $pagingId = 'pg-1'): void
    {
        Http::fake([
            self::WEBPOS_TEST_RECEPTION_HOST . '/api/fedo/ak/v1/getRecDoc/*' => Http::sequence()
                ->push($this->recDocPage($pagingId))
                ->push(['docs' => []])
                ->push($this->recDocPage($pagingId))
                ->push(['docs' => []]),
            self::WEBPOS_TEST_RECEPTION_HOST . '/api/fedo/ak/v1/getRecXml/' . self::WEBPOS_LICENSE . '/' . self::WEBPOS_API_KEY . '/' . self::VENDOR_RNC . '/' . self::ENCF => Http::response([
                'xml' => base64_encode((string) file_get_contents(__DIR__ . '/fixtures/received-e31.xml')),
                'qrContent' => 'https://ecf.dgii.gov.do/ConsultaTimbre?ENCF=' . self::ENCF . '&CodigoSeguridad=Zz9Yy8',
            ]),
            self::WEBPOS_TEST_RECEPTION_HOST . '/api/fedo/ak/v1/updRecDocDownload/*' => $onMarkDownloaded,
            self::WEBPOS_TEST_RECEPTION_HOST . '/api/fedo/ak/v1/updRecDocPaging/*' => Http::response(['ok' => true]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function recDocPage(?string $pagingId): array
    {
        return array_filter([
            'pagingId' => $pagingId,
            'docs' => [[
                'vendorRNC' => self::VENDOR_RNC,
                'vendorName' => 'Proveedora Ejemplo SRL',
                'eCF' => self::ENCF,
                'docDate' => '2026-06-10',
                'total' => 1970.00,
                'sts' => 1,
            ]],
        ], fn ($value): bool => $value !== null);
    }

    /**
     * @return Builder<Bill>
     */
    private function receivedBills(): Builder
    {
        return Bill::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('source', CreateBillFromReceivedEcfAction::SOURCE);
    }
}
