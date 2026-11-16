<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\DataTransferObject\PaymentsFinancialReport;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Services\PaymentsFinancialReportService;
use Kanvas\Souk\Payments\Enums\PaymentChannelEnum;
use Kanvas\Souk\Payments\Models\Payments;

class PaymentsFinancialReportTest extends OrderBase
{
    protected string $variantId;

    public function setUp(): void
    {
        parent::setUp();

        $product = $this->createProduct(attributes: [
            ['name' => 'slots', 'value' => 100],
        ])->json()['data']['createProduct'];

        $variant = $this->createVariant(
            productId: $product['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variant['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']]
        );

        $this->addVariantToWarehouse(
            variantId: $variant['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $this->variantId = $variant['id'];

        $this->apps->set(ConfigurationEnum::PAYMENT_REPORT_DIGITAL_FEE_RATE->value, 0.12216);
        $this->apps->set(ConfigurationEnum::PAYMENT_REPORT_PHYSICAL_FEE_RATE->value, 0.10);
    }

    public function testPaymentChannelMatchesTheDashboardRule(): void
    {
        $this->assertSame(PaymentChannelEnum::DIGITAL, PaymentChannelEnum::fromMethod('card'));
        $this->assertSame(PaymentChannelEnum::PHYSICAL, PaymentChannelEnum::fromMethod('wallet'));
        $this->assertSame(PaymentChannelEnum::PHYSICAL, PaymentChannelEnum::fromMethod('payment'));
        $this->assertSame(PaymentChannelEnum::PHYSICAL, PaymentChannelEnum::fromMethod(null));
        $this->assertSame(PaymentChannelEnum::PHYSICAL, PaymentChannelEnum::fromMethod('cash'));
        $this->assertSame(PaymentChannelEnum::PHYSICAL, PaymentChannelEnum::fromMethod('bank_transfer'));
        $this->assertSame(PaymentChannelEnum::PHYSICAL, PaymentChannelEnum::fromMethod('manual'));
    }

    public function testNetRoundsTheFeeBeforeSubtracting(): void
    {
        $this->assertSame(3511.36, PaymentsFinancialReportService::net(4000.0, 0.12216));
        $this->assertSame(3600.0, PaymentsFinancialReportService::net(4000.0, 0.10));
        $this->assertSame(0.0, PaymentsFinancialReportService::net(0.0, 0.12216));
    }

    public function testReportAndDashboardSplitChannelsTheSameWay(): void
    {
        $this->createPaidOrder('card');
        $this->createPaidOrder('wallet');

        $response = $this->graphQL('
            query OrderPaymentStats($input: OrderPaymentStatsInput) {
                orderPaymentStats(input: $input) {
                    ordersInPeriod {
                        byTransaction { card transfer cardAmount transferAmount }
                    }
                }
            }
        ', [
            'input' => [
                'paidStates' => ['paid'],
                'variantId' => (int) $this->variantId,
                'startDate' => now()->toDateString(),
                'endDate' => now()->toDateString(),
                'timezone' => 'UTC',
            ],
        ]);

        $response->assertSuccessful();
        $byTransaction = $response->json('data.orderPaymentStats.ordersInPeriod.byTransaction');
        $this->assertEquals(1, $byTransaction['card']);
        $this->assertEquals(1, $byTransaction['transfer']);

        $report = new PaymentsFinancialReportService($this->reportData())->build();
        $this->assertSame($byTransaction['card'], $report['summary']['digital']['count']);
        $this->assertSame($byTransaction['transfer'], $report['summary']['physical']['count']);
        $this->assertEqualsWithDelta((float) $byTransaction['cardAmount'], $report['summary']['digital']['gross'], 0.001);
        $this->assertEqualsWithDelta((float) $byTransaction['transferAmount'], $report['summary']['physical']['gross'], 0.001);
    }

    public function testReportSheetsAddUpToTheSameTotals(): void
    {
        $digital = $this->createPaidOrder('card');
        $physical = $this->createPaidOrder('cash');

        $report = new PaymentsFinancialReportService($this->reportData())->build();

        $this->assertCount(2, $report['transactions']);
        $this->assertSame(1, $report['summary']['digital']['count']);
        $this->assertSame(1, $report['summary']['physical']['count']);
        $this->assertEqualsWithDelta((float) $digital->total_net_amount, $report['summary']['digital']['gross'], 0.001);
        $this->assertEqualsWithDelta((float) $physical->total_net_amount, $report['summary']['physical']['gross'], 0.001);

        $expectedNet = PaymentsFinancialReportService::net((float) $digital->total_net_amount, 0.12216)
            + PaymentsFinancialReportService::net((float) $physical->total_net_amount, 0.10);
        $this->assertEqualsWithDelta($expectedNet, $report['summary']['net'], 0.001);

        $dailyGross = array_sum(array_column($report['daily'], 'gross'));
        $detailGross = $report['transactions']->sum('amount');
        $this->assertEqualsWithDelta($report['summary']['gross'], $dailyGross, 0.001);
        $this->assertEqualsWithDelta($report['summary']['gross'], $detailGross, 0.001);
    }

    public function testChannelFilterLeavesTheOtherChannelAtZero(): void
    {
        $this->createPaidOrder('card');
        $this->createPaidOrder('cash');

        $report = new PaymentsFinancialReportService($this->reportData(PaymentChannelEnum::DIGITAL))->build();

        $this->assertCount(1, $report['transactions']);
        $this->assertSame(PaymentChannelEnum::DIGITAL, $report['transactions'][0]['channel']);
        $this->assertSame(0, $report['summary']['physical']['count']);
        $this->assertSame(0.0, $report['summary']['physical']['gross']);
    }

    public function testDaysWithoutPaymentsStillGetARow(): void
    {
        $report = new PaymentsFinancialReportService($this->reportData(
            startDate: now()->subDays(2)->toDateString(),
        ))->build();

        $this->assertCount(3, $report['daily']);
        $this->assertSame(0, $report['daily'][0]['count']);
        $this->assertSame(0.0, $report['daily'][0]['net']);
    }

    public function testExportReturnsADownloadUrl(): void
    {
        $this->createPaidOrder('card');
        $this->createPaidOrder('cash');

        $response = $this->graphQL('
            query ExportPaymentsFinancialReport($input: ExportPaymentsFinancialReportInput!, $metadata: ExportMetadataInput) {
                exportPaymentsFinancialReport(input: $input, metadata: $metadata) {
                    status
                    download_url
                    file_name
                }
            }
        ', [
            'input' => [
                'startDate' => now()->toDateString(),
                'endDate' => now()->toDateString(),
                'timezone' => 'UTC',
                'paidStates' => ['paid'],
                'variantId' => (int) $this->variantId,
                'paymentChannel' => 'ALL',
            ],
            'metadata' => [
                'custom_title' => 'Reporte financiero de pagos',
                'currency_symbol' => 'RD$',
                'beneficiary' => 'ParquéaT',
            ],
        ]);

        $response->assertSuccessful();
        $this->assertSame('success', $response->json('data.exportPaymentsFinancialReport.status'));
        $this->assertNotEmpty($response->json('data.exportPaymentsFinancialReport.download_url'));
    }

    private function reportData(?PaymentChannelEnum $channel = null, ?string $startDate = null): PaymentsFinancialReport
    {
        return new PaymentsFinancialReport(
            app: $this->apps,
            user: $this->user,
            startDate: $startDate ?? now()->toDateString(),
            endDate: now()->toDateString(),
            timezone: 'UTC',
            variant: Variants::findOrFail((int) $this->variantId),
            channel: $channel,
        );
    }

    private function createPaidOrder(string $method): Order
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $this->graphQL('
            mutation addPaymentToOrder($orderID: ID!, $input: PaymentInput!) {
                addPaymentToOrder(orderID: $orderID, input: $input) {
                    status
                }
            }
        ', [
            'orderID' => $order->id,
            'input' => ['payment_method' => 'CASH'],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        Payments::query()
            ->where('payable_type', Order::class)
            ->where('payable_id', $order->getId())
            ->update(['payment_method' => $method]);

        return $order->refresh();
    }
}
