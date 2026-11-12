<?php

declare(strict_types=1);

namespace Tests\Connectors\NetSuite;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductAction;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteSalesOrderToOrderAction;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Tests\Connectors\NetSuite\Concerns\BuildsNetSuiteRecords;
use Tests\TestCase;

/**
 * `inventory` is deliberately not transacted: products are created through CreateProductAction, whose own
 * deadlock-retry transaction a savepoint would break (tests/CLAUDE.md). Barcodes are unique per test.
 */
class PullNetSuiteSalesOrderToOrderActionTest extends TestCase
{
    use BuildsNetSuiteRecords;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'social', 'commerce'];

    private Companies $company;
    private string $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = auth()->user()->getCurrentCompany();
        $this->run = (string) random_int(10000000, 99999999);
        $this->fakeNetSuite($this->company);
        $this->netsuiteCustomer('C' . $this->run, 'julie+' . Str::random(8) . '@artsupplydepo.test');
    }

    private function action(): PullNetSuiteSalesOrderToOrderAction
    {
        return $this->wireSalesOrderAction(
            new PullNetSuiteSalesOrderToOrderAction(app(Apps::class), $this->company, auth()->user())
        );
    }

    private function barcode(string $suffix): string
    {
        return '45' . $this->run . $suffix;
    }

    private function seedCatalogProduct(string $barcode, string $name): void
    {
        $this->netsuiteItem($barcode, $name);
        $this->wireProductAction(
            new PullNetSuiteProductAction(app(Apps::class), $this->company, auth()->user())
        )->execute($barcode);
    }

    public function testTheOrderMatchesTheSalesOrderLineForLineAndMissingProductsArePulled(): void
    {
        $inCatalog = $this->barcode('01');
        $missing = $this->barcode('02');
        $this->seedCatalogProduct($inCatalog, 'Copic Sketch Marker Black');
        $this->netsuiteItem($missing, 'Copic Sketch Marker Orange');
        $this->netsuiteSalesOrder('SO' . $this->run, 'C' . $this->run, [
            [$inCatalog, 'Copic Sketch Marker Black', 6, 3.14],
            [$missing, 'Copic Sketch Marker Orange', 3, 3.14],
        ]);

        $action = $this->action();
        $order = $action->execute('SO' . $this->run);

        $this->assertSame([$missing], $action->pulledProducts);
        $this->assertCount(2, $order->items()->get());
        $this->assertEqualsWithDelta(
            28.26,
            $order->items()->get()->sum(fn ($item) => $item->unit_price_net_amount * $item->quantity),
            0.001
        );
        $this->assertSame('SO' . $this->run, (string) $order->get(CustomFieldEnum::NET_SUITE_SALES_ORDER_ID->value));
        $this->assertSame('3848', (string) $order->get(CustomFieldEnum::NET_SUITE_PO_NUMBER->value));
        $this->assertSame('SOSO' . $this->run, $order->reference);
        $this->assertSame($this->company->getId(), (int) $order->companies_id);
        $this->assertNotNull($order->people);
    }

    public function testASecondRunUpdatesTheSameOrderInsteadOfCreatingAnother(): void
    {
        $barcode = $this->barcode('03');
        $this->seedCatalogProduct($barcode, 'Copic Sketch Marker Moss');
        $this->netsuiteSalesOrder('SO' . $this->run, 'C' . $this->run, [[$barcode, 'Moss', 3, 3.14]]);
        $first = $this->action()->execute('SO' . $this->run);

        $this->netsuiteSalesOrder('SO' . $this->run, 'C' . $this->run, [[$barcode, 'Moss', 5, 3.14]]);
        $second = $this->action()->execute('SO' . $this->run);

        $this->assertSame($first->getId(), $second->getId());
        $this->assertEquals(5, (float) $second->items()->first()->quantity);
    }

    public function testAProductNetSuiteCannotGiveStopsTheImportBeforeAnyOrderIsWritten(): void
    {
        $known = $this->barcode('04');
        $unknown = $this->barcode('05');
        $this->seedCatalogProduct($known, 'Copic Sketch Marker Salad');
        $this->netsuiteSalesOrder('SO' . $this->run, 'C' . $this->run, [
            [$known, 'Salad', 3, 3.14],
            [$unknown, 'Not in NetSuite', 3, 3.14],
        ]);

        try {
            $this->action()->execute('SO' . $this->run);
            $this->fail('An order with a product NetSuite cannot give must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($unknown, $e->getMessage());
        }

        $this->assertNull(Order::getByCustomFieldTransactionSafe(
            CustomFieldEnum::NET_SUITE_SALES_ORDER_ID->value,
            'SO' . $this->run,
            $this->company
        ));
    }

    public function testAnUpdateThatWouldCollapseTwoLinesIsRefusedAndRolledBack(): void
    {
        $barcode = $this->barcode('06');
        $this->seedCatalogProduct($barcode, 'Copic Sketch Marker Lime Green');
        $this->netsuiteSalesOrder('SO' . $this->run, 'C' . $this->run, [[$barcode, 'Lime Green', 3, 3.14]]);
        $order = $this->action()->execute('SO' . $this->run);

        // Same item twice: the order would keep one row and silently lose 3 units.
        $this->netsuiteSalesOrder('SO' . $this->run, 'C' . $this->run, [
            [$barcode, 'Lime Green', 3, 3.14],
            [$barcode, 'Lime Green (backorder)', 3, 3.14],
        ]);

        try {
            $this->action()->execute('SO' . $this->run);
            $this->fail('A line total that disagrees with NetSuite must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('does not match NetSuite sales order', $e->getMessage());
        }

        $quantity = (float) $order->fresh()
            ->items()
            ->first()
            ->quantity;
        $this->assertEquals(3, $quantity, 'The refused update was rolled back.');
    }

    public function testAnUnknownSalesOrderFailsClearly(): void
    {
        $this->expectExceptionMessage('Error retrieving sales order');

        $this->action()->execute('does-not-exist');
    }
}
