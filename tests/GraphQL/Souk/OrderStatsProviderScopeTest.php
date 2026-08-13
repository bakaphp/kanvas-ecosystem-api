<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

class OrderStatsProviderScopeTest extends OrderBase
{
    private const FOREIGN_PROVIDER_COMPANY_ID = 987654;

    protected string $variantId;

    /**
     * Orders live on the `commerce` connection, which DatabaseTransactions does not roll back, so
     * every test tags its own orders and filters on that tag instead of counting globally.
     */
    private string $scopeTag;

    private string $statsQuery = '
        query OrderPaymentStats($input: OrderPaymentStatsInput) {
            orderPaymentStats(input: $input) {
                ordersInPeriod { count totalAmount }
            }
        }
    ';

    public function setUp(): void
    {
        parent::setUp();

        $this->scopeTag = 'scope-' . uniqid();

        $productResponse = $this->createProduct()->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']]
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $this->variantId = $variantResponse['id'];
    }

    public function tearDown(): void
    {
        $this->apps->del('B2B_MAIN_COMPANY_ID');

        parent::tearDown();
    }

    public function testProviderOnlySeesItsOwnRevenueWhenItOmitsTheFilter(): void
    {
        $this->makeCallerAProvider();

        $this->payOrderFor($this->company->getId());
        $this->payOrderFor(self::FOREIGN_PROVIDER_COMPANY_ID);

        $this->assertEquals(
            1,
            $this->queryStats()['count'],
            'a provider that omits provider_company_id must not see another provider orders'
        );
    }

    public function testProviderCannotReadAnotherProviderByPassingItsId(): void
    {
        $this->makeCallerAProvider();

        $this->payOrderFor($this->company->getId());
        $this->payOrderFor(self::FOREIGN_PROVIDER_COMPANY_ID);

        $stats = $this->queryStats(['provider_company_id' => [(string) self::FOREIGN_PROVIDER_COMPANY_ID]]);

        $this->assertEquals(1, $stats['count'], 'the client-supplied provider_company_id must be ignored for a provider');
    }

    public function testMainCompanyKeepsItsCrossProviderView(): void
    {
        $this->apps->set('B2B_MAIN_COMPANY_ID', $this->company->getId());

        $this->payOrderFor($this->company->getId());
        $this->payOrderFor(self::FOREIGN_PROVIDER_COMPANY_ID);

        $this->assertEquals(2, $this->queryStats()['count'], 'the platform company must still see every provider');
    }

    public function testAppsWithoutTheProviderModelAreUntouched(): void
    {
        $this->payOrderFor($this->company->getId());
        $this->payOrderFor(self::FOREIGN_PROVIDER_COMPANY_ID);

        $this->assertEquals(2, $this->queryStats()['count'], 'without B2B_MAIN_COMPANY_ID the scoping must not kick in');
    }

    /**
     * Any company that is not the configured platform company is a provider, so the caller's own
     * company becomes the scope.
     */
    private function makeCallerAProvider(): void
    {
        $this->apps->set('B2B_MAIN_COMPANY_ID', self::FOREIGN_PROVIDER_COMPANY_ID);
    }

    private function payOrderFor(int $providerCompanyId): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => ['scope_tag' => $this->scopeTag]],
        );

        $order->providerCompanies()->attach($providerCompanyId);

        $this->graphQL('
            mutation addPaymentToOrder($orderID: ID!, $input: PaymentInput!) {
                addPaymentToOrder(orderID: $orderID, input: $input) {
                    status
                    payment { id }
                }
            }
        ', [
            'orderID' => $order->id,
            'input' => ['payment_method' => 'CASH'],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);
    }

    private function queryStats(array $extraInput = []): array
    {
        $response = $this->graphQL($this->statsQuery, [
            'input' => array_merge([
                'paidStates' => ['paid'],
                'startDate' => now()->subDay()->format('Y-m-d'),
                'endDate' => now()->addDay()->format('Y-m-d'),
                'timezone' => 'UTC',
                'metadata' => [
                    'path' => 'data.scope_tag',
                    'value' => $this->scopeTag,
                    'operator' => 'EQ',
                ],
            ], $extraInput),
        ]);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));

        return $response->json('data.orderPaymentStats.ordersInPeriod');
    }
}
