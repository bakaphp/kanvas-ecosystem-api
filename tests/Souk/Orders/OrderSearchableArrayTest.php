<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Tests\TestCase;

/**
 * The order search doc must be findable by order number, customer, product info and metadata.
 * order_number is an int and items/metadata are nested, so toSearchableArray() flattens them into
 * string fields the Typesense query_by can actually match — and fixes objectID / int64 timestamps
 * that the raw toArray() would otherwise get wrong.
 */
class OrderSearchableArrayTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm'];

    public function testToSearchableArrayFlattensOrderNumberCustomerProductsAndMetadata(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create(['firstname' => 'Acme', 'lastname' => 'Buyer']);

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create(['status' => 'completed', 'user_email' => 'buyer@acme.test']);

        $order->metadata = ['po_number' => 'PO-12345'];
        $order->saveOrFail();

        $item = new OrderItem();
        $item->apps_id = $app->getId();
        $item->order_id = $order->id;
        $item->variant_id = 1;
        $item->variant_name = 'Kraken Elite 360';
        $item->product_name = 'Kraken Elite 360';
        $item->product_sku = 'RL-KP336';
        $item->quantity = 1;
        $item->unit_price_net_amount = 500.0;
        $item->unit_price_gross_amount = 500.0;
        $item->quantity_fulfilled = 0;
        $item->currency = 'USD';
        $item->is_public = 1;
        $item->save();

        $doc = $order->fresh()->toSearchableArray();

        $this->assertSame($order->uuid, $doc['objectID']);
        $this->assertSame((string) $order->order_number, $doc['order_number_text']);
        $this->assertIsInt($doc['created_at'], 'created_at must be an int64 timestamp, not a date string.');
        $this->assertStringContainsString('RL-KP336', $doc['products_text']);
        $this->assertStringContainsString('Kraken Elite 360', $doc['products_text']);
        $this->assertStringContainsString('PO-12345', $doc['metadata_text']);
        $this->assertIsString($doc['customer_name']);
    }
}
