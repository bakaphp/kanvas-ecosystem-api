<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Actions\DispatchOrderReceiptAction;
use Kanvas\Souk\Orders\Jobs\GenerateOrderReceiptPdfJob;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Mockery;
use Tests\TestCase;

final class DispatchOrderReceiptActionTest extends TestCase
{
    use DatabaseTransactions;

    private function makePeople(): People
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;

        return People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create();
    }

    private function makeOrderType(string $suffix, array $config): OrderTypes
    {
        return OrderTypes::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => 0,
            'name' => 'test_type_' . $suffix,
            'config' => $config,
        ]);
    }

    private function makeOrder(OrderTypes $orderType, int $orderNumber, array $metadata = []): Order
    {
        $people = $this->makePeople();

        return Order::factory()
            ->withPeopleId($people->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create([
                'apps_id' => app(Apps::class)->getId(),
                'order_types_id' => $orderType->id,
                'order_number' => $orderNumber,
                'metadata' => $metadata ?: null,
            ]);
    }

    public function testDispatchesJobWithResolvedTemplateAndFilenameFromConfig(): void
    {
        Bus::fake();

        $orderType = $this->makeOrderType(uniqid('', true), [
            'pdf_receipt' => [
                'enabled' => true,
                'template' => 'wallet-recharge-receipt',
                'filename_pattern' => '{order_number}_recharge',
                'activity_log' => 'WALLET_RECHARGE_RECEIPT_GENERATED',
            ],
        ]);

        $orderNumber = fake()->unique()->numberBetween(1000000, 9999999);
        $order = $this->makeOrder($orderType, $orderNumber);

        $result = new DispatchOrderReceiptAction($order)->execute();

        $this->assertSame('processing', $result['status']);
        $this->assertSame("{$orderNumber}_recharge.pdf", $result['file_name']);

        Bus::assertDispatched(
            GenerateOrderReceiptPdfJob::class,
            function (GenerateOrderReceiptPdfJob $job) use ($order, $orderNumber): bool {
                return $job->entity->id === $order->id
                    && $job->html === 'wallet-recharge-receipt'
                    && $job->filename === "{$orderNumber}_recharge"
                    && $job->activityLogMessage === 'WALLET_RECHARGE_RECEIPT_GENERATED';
            }
        );
    }

    public function testSkipsWhenConfigMissingAndNoOverride(): void
    {
        Bus::fake();

        $orderType = $this->makeOrderType(uniqid('', true), []);
        $order = $this->makeOrder($orderType, fake()->unique()->numberBetween(1000000, 9999999));

        $result = new DispatchOrderReceiptAction($order)->execute();

        $this->assertSame('skipped', $result['status']);
        Bus::assertNotDispatched(GenerateOrderReceiptPdfJob::class);
    }

    public function testExplicitOverrideBypassesEnabledCheck(): void
    {
        Bus::fake();

        $orderNumber = fake()->unique()->numberBetween(1000000, 9999999);
        $orderType = $this->makeOrderType(uniqid('', true), []);
        $order = $this->makeOrder($orderType, $orderNumber);

        $result = new DispatchOrderReceiptAction(
            $order,
            [
                'template' => 'order-release-voucher',
                'filename_pattern' => '{order_number}_voucher',
                'activity_log' => 'COMPROBANTE_DESPACHO_GENERADO',
            ],
        )->execute();

        $this->assertSame('processing', $result['status']);
        $this->assertSame("{$orderNumber}_voucher.pdf", $result['file_name']);

        Bus::assertDispatched(
            GenerateOrderReceiptPdfJob::class,
            function (GenerateOrderReceiptPdfJob $job): bool {
                return $job->html === 'order-release-voucher'
                    && $job->activityLogMessage === 'COMPROBANTE_DESPACHO_GENERADO';
            }
        );
    }

    public function testSanitizesUnsafeMetadataTokensInFilename(): void
    {
        Bus::fake();

        $orderType = $this->makeOrderType(uniqid('', true), [
            'pdf_receipt' => [
                'enabled' => true,
                'template' => 'wallet-recharge-receipt',
                'filename_pattern' => '{order_number}_{vehiclePlate}',
                'activity_log' => 'TEST_LOG',
            ],
        ]);

        $order = $this->makeOrder(
            $orderType,
            fake()->unique()->numberBetween(1000000, 9999999),
            ['data' => ['vehiclePlate' => '../../etc/passwd']],
        );

        $result = new DispatchOrderReceiptAction($order)->execute();

        $this->assertSame('processing', $result['status']);
        $this->assertStringNotContainsString('..', $result['file_name']);
        $this->assertStringNotContainsString('/', $result['file_name']);
    }

    /**
     * Integration test: exercises the full template-lookup path without Bus::fake().
     *
     * This is the regression guard for the "Template not found - wallet-recharge-receipt" bug.
     * The test seeds a real email_templates row and lets generatePdfFromTemplate →
     * RenderTemplateAction → TemplatesRepository::getByName run against the DB. Only
     * PdfService::htmlToPdf is stubbed to avoid the wkhtmltopdf binary.
     *
     * The alias mock is registered before PdfService is autoloaded (guaranteed by
     * @runInSeparateProcess), so generatePdfFromTemplate()->passthru() delegates to the
     * real static method while htmlToPdf() returns a pre-built Filesystem stub.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testJobRunsSynchronouslyAndAttachesFileToOrder(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;

        $now = now()->toDateTimeString();
        DB::table('email_templates')->insert([
            'users_id' => $user->getId(),
            'companies_id' => 0,
            'apps_id' => $app->getId(),
            'name' => 'wallet-recharge-receipt',
            'title' => 'wallet-recharge-receipt',
            'subject' => 'wallet-recharge-receipt',
            'parent_template_id' => 0,
            'template' => '<html><body>Receipt for order {{ $entity->id ?? "n/a" }}</body></html>',
            'created_at' => $now,
            'updated_at' => $now,
            'is_deleted' => 0,
        ]);

        $pdfFile = new Filesystem();
        $pdfFile->apps_id = $app->getId();
        $pdfFile->companies_id = $user->getCurrentCompany()->getId();
        $pdfFile->users_id = $user->getId();
        $pdfFile->name = 'receipt-integration-test.pdf';
        $pdfFile->path = 'test/receipt-integration-test.pdf';
        $pdfFile->url = 'https://cdn.example.com/test/receipt-integration-test.pdf';
        $pdfFile->file_type = 'pdf';
        $pdfFile->size = '1024';
        $pdfFile->saveOrFail();

        $pdfServiceMock = Mockery::mock('alias:' . PdfService::class);
        $pdfServiceMock->shouldReceive('generatePdfFromTemplate')
            ->passthru();
        $pdfServiceMock->shouldReceive('htmlToPdf')
            ->once()
            ->withArgs(function (mixed $appArg, mixed $userArg, string $html): bool {
                return str_contains($html, 'Receipt for order');
            })
            ->andReturn($pdfFile);

        $orderType = $this->makeOrderType(uniqid('', true), [
            'pdf_receipt' => [
                'enabled' => true,
                'template' => 'wallet-recharge-receipt',
                'filename_pattern' => '{order_number}_recharge',
                'activity_log' => 'WALLET_RECHARGE_RECEIPT_GENERATED',
            ],
        ]);

        $orderNumber = fake()->unique()->numberBetween(1000000, 9999999);
        $order = $this->makeOrder($orderType, $orderNumber);

        $result = new DispatchOrderReceiptAction($order, [], true)->execute();

        $this->assertSame('processing', $result['status']);

        $attached = $order->refresh()->getFilesQueryBuilder()->get();
        $this->assertGreaterThan(0, $attached->count(), 'Expected at least one file attached to the order after sync dispatch');
    }

    /**
     * Negative companion: without the seeded template the action must throw.
     * This is what would have caught the round-1 "Template not found" bug at CI time.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFailsWhenTemplateNotSeeded(): void
    {
        $app = app(Apps::class);

        $pdfServiceMock = Mockery::mock('alias:' . PdfService::class);
        $pdfServiceMock->shouldReceive('generatePdfFromTemplate')->passthru();
        $pdfServiceMock->shouldReceive('htmlToPdf')->never();

        $orderType = $this->makeOrderType(uniqid('', true), [
            'pdf_receipt' => [
                'enabled' => true,
                'template' => 'wallet-recharge-receipt-missing-' . uniqid('', true),
                'filename_pattern' => '{order_number}_recharge',
                'activity_log' => 'WALLET_RECHARGE_RECEIPT_GENERATED',
            ],
        ]);

        $order = $this->makeOrder($orderType, fake()->unique()->numberBetween(1000000, 9999999));

        $this->expectException(\Kanvas\Exceptions\ModelNotFoundException::class);

        new DispatchOrderReceiptAction($order, [], true)->execute();
    }
}
