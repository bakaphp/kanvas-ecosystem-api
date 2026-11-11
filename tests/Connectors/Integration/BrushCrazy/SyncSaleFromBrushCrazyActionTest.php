<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\BrushCrazy\Actions\SyncCommerceCatalogFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncCustomerFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncSaleFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Enums\SaleLineKindEnum;
use Kanvas\Connectors\BrushCrazy\Jobs\RefundOrderFromBrushCrazyJob;
use Kanvas\Connectors\BrushCrazy\Mappers\SaleMapper;
use Kanvas\Connectors\BrushCrazy\Support\AddonPricer;
use Kanvas\Connectors\BrushCrazy\Support\IdMapRepository;
use Kanvas\Connectors\BrushCrazy\Support\RefundLedger;
use Kanvas\Connectors\BrushCrazy\Support\SaleRows;
use Kanvas\Connectors\BrushCrazy\Support\SaleSource;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Guild\Customers\Models\PeopleType;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Tests\TestCase;

final class SyncSaleFromBrushCrazyActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'ecosystem', 'commerce', 'inventory'];

    private int $saleId;

    private StudioContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        // Voided sales call unsearchable(), and fromSaleId() calls searchable().
        config(['scout.driver' => 'null', 'scout.queue' => false]);

        // Custom fields also land in Redis, which never rolls back — a fresh source id per test
        // keeps one run from resolving another run's rows.
        $this->saleId = random_int(10_000_000, 99_999_999);

        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $type = PeopleType::firstOrCreate(
            [
                'name' => 'Customer',
                'apps_id' => app(Apps::class)->getId(),
                'companies_id' => $company->getId(),
            ],
            ['users_id' => $user->getId()],
        );
        $type->set(CustomFieldEnum::BRUSHCRAZY_LOOKUP_KEY->value, 'people-type:customer');

        $this->context = new StudioContext(
            bcStudioId: 2,
            company: $company,
            branch: $company->defaultBranch,
            themeArea: new ThemeArea(),
            timezone: 'America/New_York',
        );
    }

    public function testCreatesTheOrderAsPaidHistoryForTheGuest(): void
    {
        $order = $this->action()->fromRows($this->sale(), [], AddonPricer::empty());

        $this->assertNotNull($order);
        $this->assertSame($this->saleId, (int) $order->order_number);
        $this->assertSame(PaymentStatusEnum::PAID->value, $order->payment_status);
        $this->assertSame('2024-03-01 18:00:00', $order->fresh()->paid_at->format('Y-m-d H:i:s'));
        $this->assertSame('2024-03-01', $order->created_at->format('Y-m-d'));
        $this->assertSame(37.45, (float) $order->fresh()->total_gross_amount);
        $this->assertSame($this->saleId, (int) $order->get(CustomFieldEnum::BRUSHCRAZY_SALE_ID->value));
        $this->assertSame('unknown-guest', $order->people->get(CustomFieldEnum::BRUSHCRAZY_UNKNOWN_GUEST->value));

        $item = $order->allItems()->first();
        $this->assertSame('Thursday Class', $item->product_name);
        $this->assertSame('lesson:123', $item->metadata['calendarable']);
        $this->assertSame('Class Seat', $item->variant_name);
        $this->assertSame(35.0, (float) $item->unit_price_gross_amount);
    }

    public function testReSyncUpdatesInPlaceWithoutDuplicating(): void
    {
        $first = $this->action()->fromRows($this->sale(), [], AddonPricer::empty());
        $second = $this->action()->fromRows($this->sale(items: [$this->item(['quantity' => 2])]), [], AddonPricer::empty());

        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame(1, OrderItem::withTrashed()->where('order_id', $first->getId())->count());
        $this->assertSame(2.0, (float) $first->allItems()->first()->quantity);
        $this->assertSame(72.45, (float) $second->fresh()->total_gross_amount);
    }

    public function testLineDeletedAtTheSourceIsSoftDeleted(): void
    {
        $order = $this->action()->fromRows(
            $this->sale(items: [$this->item(), $this->item(['id' => 2, 'option_type' => null, 'option_id' => null, 'product_name' => 'Apron'])]),
            [],
            AddonPricer::empty()
        );

        $this->action()->fromRows($this->sale(), [], AddonPricer::empty());

        $this->assertSame(1, OrderItem::where('order_id', $order->getId())->count());
        $this->assertSame(2, OrderItem::withTrashed()->where('order_id', $order->getId())->count());
    }

    public function testFullRefundMarksTheOrderRefunded(): void
    {
        $order = $this->action()->fromRows(
            $this->sale(['refunded_at' => '2024-03-05 12:00:00']),
            [],
            AddonPricer::empty()
        );

        $this->assertSame(PaymentStatusEnum::REVERSED->value, $order->payment_status);
        $this->assertSame('full', $order->metadata['brushcrazy']['refund_status']);
    }

    public function testPartialRefundMirrorIsNettedIntoTheParent(): void
    {
        $mirror = new SaleRows(
            sale: (object) ['id' => $this->saleId + 1, 'original_sale' => $this->saleId, 'refunded_at' => '2024-03-02 10:00:00'],
            items: [$this->item(['id' => 3, 'product_name' => 'Refund', 'category_name' => 'Refund', 'option_type' => null, 'option_id' => null, 'price' => $this->money(1000)])],
            payments: [$this->payment(['id' => 4, 'amount' => $this->money(1000)])],
        );

        $order = $this->action()->fromRows($this->sale(), [$mirror], AddonPricer::empty());

        $this->assertSame(0, Order::withTrashed()->where('order_number', $this->saleId + 1)->fromCompany($this->context->company)->count());
        $this->assertSame(PaymentStatusEnum::PAID->value, $order->payment_status);
        $this->assertSame('partial', $order->metadata['brushcrazy']['refund_status']);
        $this->assertSame(1000, $order->metadata['brushcrazy']['totals']['amount_refunded_cents']);
        $this->assertSame(2745, $order->metadata['brushcrazy']['totals']['net_cents']);
    }

    public function testBuyerIsTheStudioPeopleWithTheSameBrushCrazyUserId(): void
    {
        $bcUserId = random_int(10_000_000, 99_999_999);
        $people = new SyncCustomerFromBrushCrazyAction(
            app(Apps::class),
            auth()->user(),
            $this->context,
            credits: new FakeStudioCredits(),
        )->fromRow((object) ['id' => $bcUserId, 'name' => 'Jane Doe', 'email' => "jane{$bcUserId}@example.com"]);

        $order = $this->action()->fromRows($this->sale(['customer_id' => $bcUserId]), [], AddonPricer::empty());

        $this->assertSame($people->getId(), (int) $order->people_id);
    }

    public function testOpenSaleIsSkippedAndAVoidedOneIsSoftDeleted(): void
    {
        $this->assertNull($this->action()->fromRows($this->sale(['completed_at' => null]), [], AddonPricer::empty()));
        $this->assertSame(0, Order::withTrashed()->where('order_number', $this->saleId)->fromCompany($this->context->company)->count());

        $order = $this->action()->fromRows($this->sale(), [], AddonPricer::empty());
        $this->action()->fromRows($this->sale(['deleted_at' => '2024-03-10 09:00:00']), [], AddonPricer::empty());

        $this->assertTrue((bool) Order::withTrashed()->find($order->getId())->is_deleted);
    }

    public function testProductLineUsesTheStudioCatalogVariant(): void
    {
        $bcProductId = random_int(10_000_000, 99_999_999);
        $catalog = $this->catalog();
        $catalog->syncProductRows(
            [(object) [
                'id' => $bcProductId,
                'barcode' => 'APR-' . $bcProductId,
                'name' => 'Apron',
                'description' => null,
                'msrp' => $this->money(1500),
                'published_at' => '2020-01-01 00:00:00',
                'discontinued_at' => null,
                'deleted_at' => null,
                'created_at' => '2020-01-01 00:00:00',
            ]],
            [$bcProductId => (object) ['price' => $this->money(1200)]],
        );

        $order = new SyncSaleFromBrushCrazyAction(
            app(Apps::class),
            auth()->user(),
            $this->context,
            catalog: $catalog,
        )
            ->fromRows(
                $this->sale(items: [$this->item(['product_id' => $bcProductId, 'product_name' => 'Apron', 'option_type' => null, 'option_id' => null])]),
                [],
                AddonPricer::empty()
            );

        $item = $order->allItems()->first();
        $this->assertSame('APR-' . $bcProductId, $item->product_sku);
        $this->assertSame($catalog->variantFor($bcProductId, SaleLineKindEnum::MISC)->getId(), (int) $item->variant_id);
    }

    /** An earlier run that inserted the order and died before writing the mapping must not duplicate it. */
    public function testOrderWithoutItsMappingIsReusedAndTheMappingRewritten(): void
    {
        $order = $this->action()->fromRows($this->sale(), [], AddonPricer::empty());
        $this->forgetMapping(Order::class, [$order->getId()], CustomFieldEnum::BRUSHCRAZY_SALE_ID);

        $again = $this->action()->fromRows($this->sale(), [], AddonPricer::empty());

        $this->assertSame($order->getId(), $again->getId());
        $this->assertSame(1, Order::withTrashed()->where('order_number', $this->saleId)->fromCompany($this->context->company)->count());
        $this->assertSame(1, OrderItem::withTrashed()->where('order_id', $order->getId())->count());
        $this->assertSame(
            [(string) $this->saleId => $order->getId()],
            new IdMapRepository()->loadFor(
                $this->context->companyId(),
                Order::class,
                CustomFieldEnum::BRUSHCRAZY_SALE_ID,
                [$this->saleId],
            )
        );
    }

    /** The site numbers its own orders in the same company; the sale then takes Souk's next number. */
    public function testOrderNumberTakenByAnotherOrderGetsTheNextNumber(): void
    {
        $native = $this->action()->fromRows($this->sale(), [], AddonPricer::empty());
        $this->forgetMapping(Order::class, [$native->getId()], CustomFieldEnum::BRUSHCRAZY_SALE_ID);
        Order::withoutEvents(function () use ($native) {
            $native->metadata = [];
            $native->saveQuietly();
        });

        $order = $this->action()->fromRows($this->sale(), [], AddonPricer::empty());

        $this->assertNotSame($native->getId(), $order->getId());
        $this->assertNotSame($this->saleId, (int) $order->order_number);
        $this->assertSame($this->saleId, (int) $order->metadata['brushcrazy']['sale_id']);
        $this->assertSame([], $native->fresh()->metadata ?? []);
        $this->assertSame(
            [(string) $this->saleId => $order->getId()],
            new IdMapRepository()->loadFor(
                $this->context->companyId(),
                Order::class,
                CustomFieldEnum::BRUSHCRAZY_SALE_ID,
                [$this->saleId],
            )
        );

        $again = $this->action()->fromRows($this->sale(), [], AddonPricer::empty());

        $this->assertSame($order->getId(), $again->getId());
        $this->assertSame((int) $order->order_number, (int) $again->fresh()->order_number);
    }

    /** The site's order is the sale: only its payment state follows BrushCrazy. */
    public function testPushedSaleAdoptsTheSiteOrderAndOnlyChangesItsPaymentState(): void
    {
        $site = $this->siteOrder();
        $metadata = $site->metadata;

        $order = $this->action()->fromRows(
            $this->sale(['kanvas_order_uuid' => $site->uuid, 'refunded_at' => '2024-03-05 12:00:00']),
            [],
            AddonPricer::empty()
        );

        $fresh = Order::withTrashed()->find($site->getId());
        $this->assertSame($site->getId(), $order->getId());
        $this->assertSame(PaymentStatusEnum::REVERSED->value, $fresh->payment_status);
        $this->assertSame((int) $site->order_number, (int) $fresh->order_number);
        $this->assertSame((int) $site->people_id, (int) $fresh->people_id);
        $this->assertSame(20.0, (float) $fresh->total_gross_amount);
        $this->assertSame($metadata, $fresh->metadata);
        $this->assertSame(0, OrderItem::withTrashed()->where('order_id', $site->getId())->count());
        $this->assertSame('full', $fresh->private_metadata['brushcrazy']['refund_status']);
        $this->assertSame($this->saleId, (int) $fresh->get(CustomFieldEnum::BRUSHCRAZY_SALE_ID->value));
        $this->assertSame(SyncSaleFromBrushCrazyAction::ORIGIN_KANVAS, $fresh->get(CustomFieldEnum::BRUSHCRAZY_ORIGIN->value));
        $this->assertSame(0, Order::withTrashed()->where('order_number', $this->saleId)->fromCompany($this->context->company)->count());
    }

    public function testPushedSaleWhoseOrderIsGoneCreatesNothing(): void
    {
        $order = $this->action()->fromRows(
            $this->sale(['kanvas_order_uuid' => (string) Str::uuid()]),
            [],
            AddonPricer::empty()
        );

        $this->assertNull($order);
        $this->assertSame(0, Order::withTrashed()->where('order_number', $this->saleId)->fromCompany($this->context->company)->count());
        $this->assertSame(
            [],
            new IdMapRepository()->loadFor(
                $this->context->companyId(),
                Order::class,
                CustomFieldEnum::BRUSHCRAZY_SALE_ID,
                [$this->saleId],
            )
        );
    }

    public function testRefundMirrorOfAPushedSaleIsNettedIntoTheSiteOrder(): void
    {
        $site = $this->siteOrder();
        $mirror = new SaleRows(
            sale: (object) ['id' => $this->saleId + 1, 'original_sale' => $this->saleId, 'refunded_at' => '2024-03-02 10:00:00'],
            items: [$this->item(['id' => 3, 'product_name' => 'Refund', 'category_name' => 'Refund', 'option_type' => null, 'option_id' => null, 'price' => $this->money(1000)])],
            payments: [$this->payment(['id' => 4, 'amount' => $this->money(1000)])],
        );

        $order = $this->action()->fromRows($this->sale(['kanvas_order_uuid' => $site->uuid]), [$mirror], AddonPricer::empty());

        $this->assertSame($site->getId(), $order->getId());
        $this->assertSame(PaymentStatusEnum::PAID->value, $order->fresh()->payment_status);
        $this->assertSame('partial', $order->fresh()->private_metadata['brushcrazy']['refund_status']);
        $this->assertSame(1000, $order->fresh()->private_metadata['brushcrazy']['totals']['amount_refunded_cents']);
        $this->assertSame($site->metadata, $order->fresh()->metadata);
        $this->assertSame(0, Order::withTrashed()->where('order_number', $this->saleId + 1)->fromCompany($this->context->company)->count());
    }

    public function testNewRefundOfTheKanvasPaymentQueuesOneRefundOfTheDelta(): void
    {
        Queue::fake();
        $site = $this->siteOrder();
        $sale = $this->sale(['kanvas_order_uuid' => $site->uuid]);
        $mirror = $this->kanvasRefundMirror(1500);

        $this->action()->fromRows($sale, [$mirror], AddonPricer::empty());

        Queue::assertPushed(RefundOrderFromBrushCrazyJob::class, 1);
        Queue::assertPushed(
            RefundOrderFromBrushCrazyJob::class,
            fn (RefundOrderFromBrushCrazyJob $job) => $job->refundKey === 'sale:' . ($this->saleId + 1) && $job->order->getId() === $site->getId()
        );

        $entry = RefundLedger::of($site->fresh())['sale:' . ($this->saleId + 1)];
        $this->assertSame(1500, $entry['cents']);
        $this->assertSame(RefundLedger::PENDING, $entry['status']);
        $this->assertArrayNotHasKey('brushcrazy', $site->fresh()->metadata ?? []);
    }

    public function testRefundTheJobSettledIsNotQueuedAgainOnTheNextSync(): void
    {
        Queue::fake();
        $site = $this->siteOrder();
        $sale = $this->sale(['kanvas_order_uuid' => $site->uuid]);
        $mirror = $this->kanvasRefundMirror(1500);
        $this->action()->fromRows($sale, [$mirror], AddonPricer::empty());

        RefundLedger::update($site, function (array $ledger) {
            $ledger['sale:' . ($this->saleId + 1)]['status'] = RefundLedger::REFUNDED;

            return $ledger;
        });

        $this->action()->fromRows($sale, [$mirror], AddonPricer::empty());

        Queue::assertPushed(RefundOrderFromBrushCrazyJob::class, 1);
        $this->assertSame(RefundLedger::REFUNDED, RefundLedger::of($site->fresh())['sale:' . ($this->saleId + 1)]['status']);
    }

    public function testRefundToStudioCreditMovesNoMoney(): void
    {
        Queue::fake();
        $site = $this->siteOrder();
        $creditMirror = new SaleRows(
            sale: (object) ['id' => $this->saleId + 1, 'original_sale' => $this->saleId, 'refunded_at' => '2024-03-02 10:00:00'],
            items: [$this->item(['id' => 3])],
            payments: [],
        );

        $this->action()->fromRows($this->sale(['kanvas_order_uuid' => $site->uuid]), [$creditMirror], AddonPricer::empty());

        Queue::assertNotPushed(RefundOrderFromBrushCrazyJob::class);
        $this->assertSame([], RefundLedger::of($site->fresh()));
    }

    /** A refund made in Kanvas comes back through the mirror with BrushCrazy's marker on it. */
    public function testRefundKanvasPushedIsNotRefundedAgainWhenMirroredBack(): void
    {
        Queue::fake();
        $site = $this->siteOrder();
        $mirror = $this->kanvasRefundMirror(1000, [
            'card_number' => SaleMapper::KANVAS_REFUND_CARD_PREFIX . 'kanvas-refund-uuid',
            'approval_code' => SaleMapper::KANVAS_REFUND_APPROVAL_CODE,
        ]);

        $this->action()->fromRows($this->sale(['kanvas_order_uuid' => $site->uuid]), [$mirror], AddonPricer::empty());

        Queue::assertNotPushed(RefundOrderFromBrushCrazyJob::class);
        $entry = RefundLedger::of($site->fresh())['sale:' . ($this->saleId + 1)];
        $this->assertSame(RefundLedger::FROM_KANVAS, $entry['status']);
        $this->assertSame('kanvas-refund-uuid', $entry['kanvas_refund_id']);
    }

    /** The webhook can read the sale before BrushCrazy stamps the uuid; that mirror order gives way. */
    public function testMirrorOrderBuiltBeforeTheUuidLandedGivesWayToTheSiteOrder(): void
    {
        $site = $this->siteOrder();
        $mirrorOrder = $this->action()->fromRows($this->sale(), [], AddonPricer::empty());

        $order = $this->action()->fromRows($this->sale(['kanvas_order_uuid' => $site->uuid]), [], AddonPricer::empty());

        $this->assertSame($site->getId(), $order->getId());
        $this->assertTrue((bool) Order::withTrashed()->find($mirrorOrder->getId())->is_deleted);
        $this->assertSame(
            [(string) $this->saleId => $site->getId()],
            new IdMapRepository()->loadFor(
                $this->context->companyId(),
                Order::class,
                CustomFieldEnum::BRUSHCRAZY_SALE_ID,
                [$this->saleId],
            )
        );
    }

    public function testVoidedSaleThatComesBackRestoresTheSameOrder(): void
    {
        $order = $this->action()->fromRows($this->sale(), [], AddonPricer::empty());
        $this->action()->fromRows($this->sale(['deleted_at' => '2024-03-10 09:00:00']), [], AddonPricer::empty());

        $restored = $this->action()->fromRows($this->sale(), [], AddonPricer::empty());

        $this->assertSame($order->getId(), $restored->getId());
        $this->assertFalse((bool) Order::withTrashed()->find($order->getId())->is_deleted);
        $this->assertSame(1, Order::withTrashed()->where('order_number', $this->saleId)->fromCompany($this->context->company)->count());
    }

    public function testMirrorSaleIdSyncsItsParent(): void
    {
        $mirrorId = $this->saleId + 1;
        $source = $this->source([
            $this->saleId => $this->sale(),
            $mirrorId => $this->sale(['id' => $mirrorId, 'original_sale' => $this->saleId, 'refunded_at' => '2024-03-02 10:00:00']),
        ]);

        $result = $this->action($source)->fromSaleId($mirrorId);

        $this->assertSame(SyncSaleFromBrushCrazyAction::SYNCED, $result);
        $this->assertSame(1, Order::where('order_number', $this->saleId)->fromCompany($this->context->company)->count());
        $this->assertSame(0, Order::withTrashed()->where('order_number', $mirrorId)->fromCompany($this->context->company)->count());
    }

    public function testSaleOfAnotherStudioIsMisroutedAndAnUnknownOneMissing(): void
    {
        $source = $this->source([$this->saleId => $this->sale(['studio_id' => 99])]);

        $this->assertSame(SyncSaleFromBrushCrazyAction::MISROUTED, $this->action($source)->fromSaleId($this->saleId));
        $this->assertSame(SyncSaleFromBrushCrazyAction::MISSING, $this->action($source)->fromSaleId($this->saleId + 5));
        $this->assertSame(SyncSaleFromBrushCrazyAction::MISSING, $this->action($source)->fromPointer('sale', $this->saleId + 5));
        $this->assertSame(0, Order::withTrashed()->where('order_number', $this->saleId)->fromCompany($this->context->company)->count());
    }

    public function testGenericCatalogLineIsNotDuplicatedOnReRunsOrWithoutItsMapping(): void
    {
        $first = $this->catalog()->genericVariant(SaleLineKindEnum::TIP);
        $this->assertSame($first->getId(), $this->catalog()->genericVariant(SaleLineKindEnum::TIP)->getId());

        $this->forgetMapping(Variants::class, [$first->getId()], CustomFieldEnum::BRUSHCRAZY_LOOKUP_KEY);
        $this->forgetMapping(Products::class, [(int) $first->products_id], CustomFieldEnum::BRUSHCRAZY_LOOKUP_KEY);

        $again = $this->catalog()->genericVariant(SaleLineKindEnum::TIP);

        $this->assertSame($first->getId(), $again->getId());
        $this->assertSame((int) $first->products_id, (int) $again->products_id);
        $this->assertSame(
            1,
            Variants::withTrashed()
                ->where('companies_id', $this->context->companyId())
                ->where('slug', 'bc-sale-line-' . SaleLineKindEnum::TIP->value)
                ->count()
        );
    }

    /**
     * @param  class-string  $modelClass
     * @param  array<int, int>  $entityIds
     */
    private function forgetMapping(string $modelClass, array $entityIds, CustomFieldEnum $field): void
    {
        DB::connection('ecosystem')->table('apps_custom_fields')
            ->where('companies_id', $this->context->companyId())
            ->where('model_name', $modelClass)
            ->whereIn('entity_id', $entityIds)
            ->where('name', $field->value)
            ->delete();
    }

    /**
     * Stands in for the BrushCrazy database. The parent constructor is skipped on purpose: it opens
     * a connection from the app's BrushCrazy settings, which no test has.
     *
     * @param  array<int, SaleRows>  $sales
     */
    private function source(array $sales): SaleSource
    {
        return new class ($sales) extends SaleSource {
            /**
             * @param  array<int, SaleRows>  $sales
             */
            public function __construct(private array $sales)
            {
            }

            public function rootSaleId(string $morph, int $entityId): ?int
            {
                $sale = $this->sales[$entityId] ?? null;

                return $sale === null ? null : (int) ($sale->sale->original_sale ?? $sale->id());
            }

            public function find(int $saleId): ?object
            {
                return ($this->sales[$saleId] ?? null)?->sale;
            }

            public function bundles(iterable $saleRows): array
            {
                $bundles = [];

                foreach ($saleRows as $row) {
                    $bundles[(int) $row->id] = $this->sales[(int) $row->id];
                }

                return $bundles;
            }

            public function refundsFor(array $parentIds): array
            {
                return [];
            }

            public function pricer(): AddonPricer
            {
                return AddonPricer::empty();
            }
        };
    }

    /** A paid order the way the site leaves it: its own number, buyer, totals and verified metadata. */
    private function siteOrder(): Order
    {
        $user = auth()->user();

        return Order::withoutEvents(fn () => Order::factory()
            ->withCompanyId($this->context->companyId())
            ->withUserId($user->getId())
            ->completed()
            ->create([
                'order_number' => $this->saleId + 7,
                'region_id' => $this->catalog()->region()->getId(),
                'total_gross_amount' => 20.0,
                'payment_status' => PaymentStatusEnum::PAID->value,
                'paid_at' => '2024-03-01 18:00:00',
                'metadata' => ['schema' => 'brushcrazy.event-order.v1', 'reservation_key' => 'res-' . $this->saleId],
            ]));
    }

    private function action(?SaleSource $source = null): SyncSaleFromBrushCrazyAction
    {
        return new SyncSaleFromBrushCrazyAction(
            app(Apps::class),
            auth()->user(),
            $this->context,
            source: $source,
        );
    }

    private function catalog(): SyncCommerceCatalogFromBrushCrazyAction
    {
        return new SyncCommerceCatalogFromBrushCrazyAction(app(Apps::class), auth()->user(), $this->context);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<int, object>|null  $items
     */
    private function sale(array $overrides = [], ?array $items = null): SaleRows
    {
        return new SaleRows(
            sale: (object) ($overrides + [
                'id' => $this->saleId,
                'ulid' => '01HQ' . $this->saleId,
                'customer_id' => null,
                'studio_id' => 2,
                'tax_exempt' => null,
                'completed_at' => '2024-03-01 18:00:00',
                'refunded_at' => null,
                'original_sale' => null,
                'cart_id' => null,
                'created_at' => '2024-03-01 17:30:00',
                'deleted_at' => null,
            ]),
            items: $items ?? [$this->item()],
            taxes: [(object) ['id' => 1, 'name' => 'Sales Tax', 'type' => 'fixed', 'rate' => '7', 'amount' => $this->money(245)]],
            payments: [$this->payment()],
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function item(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => 1,
            'product_id' => null,
            'product_name' => 'Thursday Class',
            'category_name' => 'Classes',
            'quantity' => 1,
            'price' => $this->money(3500),
            'option_type' => 'lesson',
            'option_id' => 123,
            'double_sided' => 0,
            'first_option_id' => null,
            'second_option_id' => null,
            'addons' => null,
            'extra_colors' => 0,
            'extra_gold' => 0,
            'extra_silver' => 0,
            'discount' => null,
            'giveaway' => null,
            'metadata' => null,
            'taxable' => 1,
            'refunded_at' => null,
            'created_at' => '2024-03-01 17:31:00',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function payment(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => 1,
            'name' => 'Cash',
            'type' => 'Cash',
            'amount' => $this->money(3745),
            'card_number' => null,
            'approval_code' => null,
            'transaction_id' => null,
            'completed_at' => '2024-03-01 18:00:00',
            'refunded_at' => null,
        ]);
    }

    private function money(int $cents): string
    {
        return '{"amount":' . $cents . ',"currency":"USD"}';
    }

    /**
     * An amount refund staff booked back to the payment the site charged (type Kanvas).
     *
     * @param  array<string, mixed>  $payment
     */
    private function kanvasRefundMirror(int $cents, array $payment = []): SaleRows
    {
        return new SaleRows(
            sale: (object) ['id' => $this->saleId + 1, 'original_sale' => $this->saleId, 'refunded_at' => '2024-03-02 10:00:00'],
            items: [$this->item(['id' => 3, 'product_name' => 'Refund', 'category_name' => 'Refund', 'option_type' => null, 'option_id' => null, 'price' => $this->money($cents)])],
            payments: [$this->payment($payment + ['id' => 4, 'name' => 'Kanvas stripe', 'type' => SaleMapper::KANVAS_PAYMENT_TYPE, 'amount' => $this->money($cents)])],
        );
    }
}
