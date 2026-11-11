<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\PasoRapido;

use App\Console\Commands\Connectors\PasoRapido\StorePasoRapidoInvoicePdfsCommand;
use Baka\Http\SafeUrlFetcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PasoRapido\Actions\StorePasoRapidoInvoicePdfAction;
use Kanvas\Connectors\PasoRapido\Jobs\StorePasoRapidoInvoicePdfJob;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use RuntimeException;
use Tests\TestCase;

final class StorePasoRapidoInvoicePdfTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'commerce'];

    private const string SOURCE_URL = 'https://internal.pasorapido.gob.do/printer/print/CreateInvoice?input=abc';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::partialMock()->shouldReceive('build')->andReturn(Storage::fake('paso-rapido-invoices'));
    }

    public function testItStoresTheInvoicePdfAndLinksItInMetadataAndFiles(): void
    {
        $fetched = [];
        SafeUrlFetcher::fake(function (string $url) use (&$fetched): string {
            $fetched[] = $url;

            return "%PDF-1.5\nfake invoice";
        });

        $order = $this->createOrderWithInvoice(['invoice' => 'B0208998075', 'pdf' => self::SOURCE_URL]);

        $url = new StorePasoRapidoInvoicePdfAction($order)->execute();

        $this->assertNotEmpty($url);
        $this->assertSame([self::SOURCE_URL], $fetched);

        $order = $order->fresh();
        $invoice = $order->metadata['data']['invoice'];
        $this->assertSame($url, $invoice['pdf_url']);
        $this->assertSame(self::SOURCE_URL, $invoice['pdf']);
        $this->assertSame('B0208998075', $invoice['invoice']);

        $files = $order->getFiles()->where('field_name', StorePasoRapidoInvoicePdfAction::FILE_FIELD);
        $this->assertCount(1, $files);
    }

    public function testItRejectsAResponseThatIsNotAPdf(): void
    {
        SafeUrlFetcher::fake(fn (string $url): string => '<html>Object moved</html>');

        $order = $this->createOrderWithInvoice(['pdf' => self::SOURCE_URL]);

        try {
            new StorePasoRapidoInvoicePdfAction($order)->execute();
            $this->fail('A non-PDF response must not be stored');
        } catch (ValidationException) {
        }

        $order = $order->fresh();
        $this->assertArrayNotHasKey('pdf_url', $order->metadata['data']['invoice']);
        $this->assertCount(0, $order->getFiles()->where('field_name', StorePasoRapidoInvoicePdfAction::FILE_FIELD));
    }

    public function testItSkipsAnOrderWithoutAnInvoiceLink(): void
    {
        SafeUrlFetcher::fake(fn (string $url): string => throw new RuntimeException('must not fetch'));

        $order = $this->createOrderWithInvoice(['pdf' => '']);

        $this->assertNull(new StorePasoRapidoInvoicePdfAction($order)->execute());
    }

    public function testItDoesNotDownloadAgainWhenAlreadyStored(): void
    {
        SafeUrlFetcher::fake(fn (string $url): string => throw new RuntimeException('must not fetch'));

        $order = $this->createOrderWithInvoice([
            'pdf' => self::SOURCE_URL,
            'pdf_url' => 'https://cdn.example.com/comprobante.pdf',
        ]);

        $this->assertSame(
            'https://cdn.example.com/comprobante.pdf',
            new StorePasoRapidoInvoicePdfAction($order)->execute()
        );
    }

    public function testTheBackfillCommandQueuesOnlyOrdersStillMissingTheirCopy(): void
    {
        Bus::fake([StorePasoRapidoInvoicePdfJob::class]);
        Artisan::registerCommand(app(StorePasoRapidoInvoicePdfsCommand::class));

        $pending = $this->createOrderWithInvoice(['pdf' => self::SOURCE_URL]);
        $stored = $this->createOrderWithInvoice(['pdf' => self::SOURCE_URL, 'pdf_url' => 'https://cdn.example.com/a.pdf']);
        $withoutLink = $this->createOrderWithInvoice(['pdf' => '']);

        $this->artisan('kanvas-paso-rapido:store-invoice-pdfs', ['app_id' => app(Apps::class)->getId()])
            ->assertSuccessful();

        Bus::assertDispatched(StorePasoRapidoInvoicePdfJob::class, fn (StorePasoRapidoInvoicePdfJob $job) => $job->order->is($pending));
        Bus::assertNotDispatched(StorePasoRapidoInvoicePdfJob::class, fn (StorePasoRapidoInvoicePdfJob $job) => $job->order->is($stored));
        Bus::assertNotDispatched(StorePasoRapidoInvoicePdfJob::class, fn (StorePasoRapidoInvoicePdfJob $job) => $job->order->is($withoutLink));
    }

    private function createOrderWithInvoice(array $invoice): Order
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $person = People::withoutSyncingToSearch(
            fn () => People::factory()
                ->withAppId($app->getId())
                ->withCompanyId($company->getId())
                ->create()
        );

        return Order::withoutSyncingToSearch(
            fn () => Order::factory()
                ->withAppId($app->getId())
                ->withCompanyId($company->getId())
                ->withUserId($user->getId())
                ->create([
                    'region_id' => Regions::getDefault($company, $app)->getId(),
                    'people_id' => $person->id,
                    'metadata' => [
                        'data' => [
                            'paso_rapido_tag' => '00603952',
                            'invoice' => [
                                'commercialName' => '',
                                'document' => '',
                                'fiscalCredit' => false,
                                'invoice' => 'B0200000001',
                                'reference' => '603952',
                                ...$invoice,
                            ],
                        ],
                    ],
                ])
        );
    }
}
