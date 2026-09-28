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

    /**
     * The raw metadata / private_metadata objects must never reach the search engine, regardless
     * of size. Typesense (enable_nested_fields) auto-detects a type per nested key and then rejects
     * any later document that disagrees — Sentry KANVAS-ECOSYSTEM-628: metadata.data.business_days_late
     * locked to int64 from whole-number orders, so a half-day 5.5 failed the whole import. The scalar
     * still has to survive in metadata_text so search can match it.
     */
    public function testToSearchableArrayNeverIndexesRawMetadataObjects(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create(['status' => 'completed']);

        $order->metadata = ['data' => ['business_days_late' => 5.5, 'po_number' => 'PO-99']];
        $order->private_metadata = ['secret' => 'do-not-index'];
        $order->saveOrFail();

        $doc = $order->fresh()->toSearchableArray();

        $this->assertArrayNotHasKey('metadata', $doc, 'Raw nested metadata object must never be indexed.');
        $this->assertArrayNotHasKey('private_metadata', $doc, 'private_metadata would leak secrets into the index.');
        $this->assertStringContainsString('5.5', $doc['metadata_text'], 'Fractional scalar must survive in metadata_text.');
        $this->assertStringContainsString('PO-99', $doc['metadata_text']);
    }

    /**
     * Algolia fails the whole batch when a single record is over its byte limit (Sentry
     * KANVAS-ECOSYSTEM-5SS: an order with a huge integration metadata blob hit 101412/100000).
     * The doc must be trimmed to fit while keeping the fields search actually queries on.
     */
    public function testToSearchableArrayTrimsOversizedOrderForAlgolia(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create(['status' => 'completed']);

        // A raw integration payload well over Algolia's 100KB record limit.
        $order->metadata = ['integration_payload' => str_repeat('x', 120000)];
        $order->private_metadata = ['raw' => str_repeat('y', 120000)];
        $order->saveOrFail();

        // Force the Algolia record-limit path in-memory. Persisting search_engine=algolia would leak
        // through shared Redis and make every other test index to Algolia with no creds.
        $fresh = $order->fresh();
        $fresh->setAlgolia(true);
        $doc = $fresh->toSearchableArray();

        $this->assertLessThanOrEqual(100000, strlen((string) json_encode($doc)), 'Record must fit under Algolia 100KB limit.');
        $this->assertArrayNotHasKey('metadata', $doc, 'Raw metadata blob must be dropped when over budget.');
        $this->assertArrayNotHasKey('private_metadata', $doc);
        $this->assertSame($order->uuid, $doc['objectID']);
        $this->assertSame((string) $order->order_number, $doc['order_number_text']);
    }
}
