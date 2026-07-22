<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\BulkRechargeOrderTagsAction;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Connectors\Movipass\Workflows\Activities\BulkRechargeTagsActivity;
use Kanvas\Connectors\PasoRapido\DataTransferObject\InvoiceDetails;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmData;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmResponse;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem as OrderItemData;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Mockery;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class BulkRechargeTagsActivityTest extends TestCase
{
    use HasIntegrationCompany;

    protected Apps $kanvasApp;
    private Users $kanvasUser;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass bulk recharge activity tests are skipped in CI');
        }

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->kanvasUser = $user;
        $company = $user->getCurrentCompany();

        $this->setIntegration(
            $this->kanvasApp,
            IntegrationsEnum::MOVIPASS,
            MovipassHandler::class,
            $company,
            $user,
        );
    }

    /**
     * @return Variants[]
     */
    private function createTagVariants(int $count): array
    {
        $company = $this->kanvasUser->getCurrentCompany();
        $product = Products::factory()
            ->state([
                'apps_id' => $this->kanvasApp->getId(),
                'companies_id' => $company->getId(),
                'users_id' => $this->kanvasUser->getId(),
            ])
            ->create();

        $variants = [];
        for ($i = 0; $i < $count; $i++) {
            $variants[] = Variants::factory()
                ->withProductId($product->getId())
                ->create([
                    'apps_id' => $this->kanvasApp->getId(),
                    'companies_id' => $company->getId(),
                    'users_id' => $this->kanvasUser->getId(),
                ]);
        }

        return $variants;
    }

    /**
     * @param  array<int, array{variant: Variants, tag_number: string, amount: float}>  $tags
     */
    private function createBulkRechargeOrder(array $tags): Order
    {
        $company = $this->kanvasUser->getCurrentCompany();

        $people = People::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($company->getId())
            ->withUserId($this->kanvasUser->getId())
            ->create();

        $total = (float) array_sum(array_column($tags, 'amount'));

        $order = Order::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($company->getId())
            ->withUserId($this->kanvasUser->getId())
            ->withPeopleId($people->getId())
            ->create([
                'total_gross_amount' => $total,
                'total_net_amount' => $total,
                'currency' => 'DOP',
                'status' => 'completed',
                'payment_status' => 'paid',
                'metadata' => [
                    'data' => ['is_bulk_recharge' => true],
                ],
            ]);

        $order->setOrderType(OrderTypeEnum::PASO_RAPIDO->value);

        $currency = Currencies::where('code', 'DOP')->firstOrFail();

        foreach ($tags as $tag) {
            $order->addItem(new OrderItemData(
                app: $this->kanvasApp,
                variant: $tag['variant'],
                name: $tag['variant']->name,
                sku: $tag['variant']->sku,
                quantity: 1,
                price: $tag['amount'],
                tax: 0.0,
                discount: 0.0,
                currency: $currency,
                metadata: [
                    'tag_number' => $tag['tag_number'],
                    'bulk_recharge' => true,
                ],
            ));
        }

        return $order->fresh();
    }

    private function makeActivity(): BulkRechargeTagsActivity
    {
        return new BulkRechargeTagsActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            [],
        );
    }

    public function testActivitySkipsWhenOrderIsNotFlaggedAsBulk(): void
    {
        [$tag1] = $this->createTagVariants(1);
        $order = $this->createBulkRechargeOrder([
            ['variant' => $tag1, 'tag_number' => '941001', 'amount' => 500.00],
        ]);

        $metadata = $order->metadata;
        unset($metadata['data']['is_bulk_recharge']);
        $order->metadata = $metadata;
        $order->saveOrFail();

        $result = $this->makeActivity()->execute($order, $this->kanvasApp, []);

        $this->assertTrue(($result['skipped'] ?? false));
    }

    public function testActivityCreditsWalletAndProcessesEachTag(): void
    {
        $company = $this->kanvasUser->getCurrentCompany();
        $wallet = $company->createAppWallet($this->kanvasApp, ['name' => 'default']);
        $balanceBefore = (float) $wallet->balanceFloat;

        [$tag1, $tag2] = $this->createTagVariants(2);
        $order = $this->createBulkRechargeOrder([
            ['variant' => $tag1, 'tag_number' => '941001', 'amount' => 500.00],
            ['variant' => $tag2, 'tag_number' => '941002', 'amount' => 1000.00],
        ]);

        $this->makeActivity()->execute($order, $this->kanvasApp, []);

        $order->refresh();
        $this->assertArrayHasKey('corporate_recharge_results', $order->metadata ?? []);
        $results = $order->metadata['corporate_recharge_results'];
        $this->assertArrayHasKey('941001', $results);
        $this->assertArrayHasKey('941002', $results);

        $wallet->refresh();
        $this->assertEqualsWithDelta(
            $balanceBefore + 1500.00,
            (float) $wallet->balanceFloat,
            0.01,
        );
    }

    public function testActivityIsIdempotentOnceProcessed(): void
    {
        [$tag1] = $this->createTagVariants(1);
        $order = $this->createBulkRechargeOrder([
            ['variant' => $tag1, 'tag_number' => '941001', 'amount' => 500.00],
        ]);

        $metadata = $order->metadata ?? [];
        $metadata['corporate_recharge_results'] = ['941001' => ['status' => 'success', 'amount' => 500.00]];
        $order->metadata = $metadata;
        $order->saveOrFail();

        $result = $this->makeActivity()->execute($order, $this->kanvasApp, []);

        $this->assertTrue(($result['skipped'] ?? false));
        $this->assertSame('already processed', $result['reason'] ?? null);
    }

    public function testActivityCancelsOrderAndZeroesTotalWhenAllItemsFailAndReverse(): void
    {
        [$tag1, $tag2] = $this->createTagVariants(2);
        $order = $this->createBulkRechargeOrder([
            ['variant' => $tag1, 'tag_number' => '941001', 'amount' => 500.00],
            ['variant' => $tag2, 'tag_number' => '941002', 'amount' => 1000.00],
        ]);

        $this->makeActivity()->execute($order, $this->kanvasApp, []);

        $order->refresh();

        $this->assertSame(0.0, (float) $order->total_gross_amount);
        $this->assertSame('canceled', $order->status);
        $this->assertSame('refunded', $order->payment_status);
        $this->assertSame('canceled', $order->fulfillment_status);
        $this->assertSame(0, $order->items()->where('is_deleted', 0)->count());
    }

    public function testActivityPreservesOriginalTotalAndAdjustmentReasonInMetadata(): void
    {
        [$tag1] = $this->createTagVariants(1);
        $order = $this->createBulkRechargeOrder([
            ['variant' => $tag1, 'tag_number' => '941001', 'amount' => 750.00],
        ]);

        $this->makeActivity()->execute($order, $this->kanvasApp, []);

        $order->refresh();

        $this->assertSame(750.0, (float) ($order->metadata['data']['original_total_gross_amount'] ?? null));
        $this->assertSame('bulk_recharge_partial_failure', $order->metadata['data']['adjusted_reason'] ?? null);
        $this->assertArrayHasKey('adjusted_at', $order->metadata['data'] ?? []);
    }

    public function testActivityMarksReversedFlagOnFailedItems(): void
    {
        [$tag1] = $this->createTagVariants(1);
        $order = $this->createBulkRechargeOrder([
            ['variant' => $tag1, 'tag_number' => '941001', 'amount' => 500.00],
        ]);

        $this->makeActivity()->execute($order, $this->kanvasApp, []);

        $order->refresh();

        $entry = $order->metadata['corporate_recharge_results']['941001'] ?? null;

        $this->assertNotNull($entry);
        $this->assertSame('failed', $entry['status']);
        $this->assertTrue($entry['reversed'] ?? false);
    }

    public function testBulkRechargeRequestsFiscalCreditWhenCompanyHasRnc(): void
    {
        $company = $this->kanvasUser->getCurrentCompany();
        $rnc = '130987654';
        $company->set('rnc', $rnc);

        [$tag1] = $this->createTagVariants(1);
        $order = $this->createBulkRechargeOrder([
            ['variant' => $tag1, 'tag_number' => '941001', 'amount' => 500.00],
        ]);

        $captured = [];
        $mockService = Mockery::mock(PasoRapidoService::class);
        $mockService->shouldReceive('confirmPayment')
            ->andReturnUsing(function (PaymentConfirmData $data) use (&$captured) {
                $captured[] = $data;

                return PaymentConfirmResponse::from([
                    'message' => 'ok',
                    'amount' => (int) round($data->amount),
                    'order' => 'ord-' . $data->reference,
                    'tag' => $data->reference,
                    'account' => '1234',
                    'creditDate' => '2026-06-01',
                    'invoiceDetails' => InvoiceDetails::from([
                        'commercialName' => '',
                        'document' => $data->dni,
                        'fiscalCredit' => $data->fiscalCredit,
                        'invoice' => '',
                        'pdf' => '',
                        'reference' => '',
                    ]),
                ]);
            });

        new BulkRechargeOrderTagsAction($order, $this->kanvasApp, $mockService)->execute();

        $this->assertNotEmpty($captured, 'PasoRapidoService::confirmPayment was never called');
        $this->assertTrue($captured[0]->fiscalCredit);
        $this->assertSame($rnc, $captured[0]->dni);

        $company->del('rnc');
    }

    public function testBankTransactionStaysWithinPasoRapidoFiftyCharLimit(): void
    {
        // PasoRapido rejects transaccionBanco > 50 chars. The wallet-paid bulk path
        // is the only producer of this id (no EchoPay intent to crib from), so we
        // need to keep its format short on every TAG.
        [$tag1, $tag2, $tag3] = $this->createTagVariants(3);
        $order = $this->createBulkRechargeOrder([
            ['variant' => $tag1, 'tag_number' => '941001', 'amount' => 500.00],
            ['variant' => $tag2, 'tag_number' => '941002', 'amount' => 1000.00],
            ['variant' => $tag3, 'tag_number' => '941003', 'amount' => 250.00],
        ]);

        $captured = [];
        $mockService = Mockery::mock(PasoRapidoService::class);
        $mockService->shouldReceive('confirmPayment')
            ->andReturnUsing(function (PaymentConfirmData $data) use (&$captured) {
                $captured[] = $data;

                return PaymentConfirmResponse::from([
                    'message' => 'ok',
                    'amount' => (int) round($data->amount),
                    'order' => 'ord-' . $data->reference,
                    'tag' => $data->reference,
                    'account' => '1234',
                    'creditDate' => '2026-06-01',
                    'invoiceDetails' => InvoiceDetails::from([
                        'commercialName' => '',
                        'document' => '',
                        'fiscalCredit' => false,
                        'invoice' => '',
                        'pdf' => '',
                        'reference' => '',
                    ]),
                ]);
            });

        new BulkRechargeOrderTagsAction($order, $this->kanvasApp, $mockService)->execute();

        $this->assertNotEmpty($captured, 'PasoRapidoService::confirmPayment was never called');

        foreach ($captured as $data) {
            $this->assertLessThanOrEqual(
                50,
                strlen($data->bankTransaction),
                sprintf(
                    'bankTransaction "%s" (length %d) exceeds PasoRapido limit of 50 chars',
                    $data->bankTransaction,
                    strlen($data->bankTransaction),
                ),
            );
        }
    }
}
