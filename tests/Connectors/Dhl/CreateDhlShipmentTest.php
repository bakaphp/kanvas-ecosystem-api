<?php

declare(strict_types=1);

namespace Tests\Connectors\Dhl;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Dhl\Actions\AttachDhlDocumentAction;
use Kanvas\Connectors\Dhl\Actions\CreateDhlShipmentAction;
use Kanvas\Connectors\Dhl\Activities\CreateDhlShipmentActivity;
use Kanvas\Connectors\Dhl\Activities\NotifyOrderStatusActivity;
use Kanvas\Connectors\Dhl\Enums\BookingStateEnum;
use Kanvas\Connectors\Dhl\Enums\ConfigurationEnum;
use Kanvas\Connectors\Dhl\Enums\CustomFieldEnum;
use Kanvas\Connectors\Dhl\Enums\CustomsAttributeEnum;
use Kanvas\Connectors\Dhl\Exceptions\DhlBookingException;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Locations\Models\Countries;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\Souk\Orders\Notifications\OrderNotification;
use Kanvas\Souk\Shipping\Enums\ConfigurationEnum as ShippingConfigurationEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Connectors\Traits\CreatesDhlOrder;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

#[Group('serial')]
final class CreateDhlShipmentTest extends TestCase
{
    use CreatesDhlOrder;
    use DatabaseTransactions;
    use InventoryCases;

    protected $connectionsToTransact = [null, 'workflow', 'commerce', 'inventory', 'crm', 'ecosystem'];

    private Companies $company;
    private Regions $region;

    protected function setUp(): void
    {
        parent::setUp();

        $user = auth()->user();
        $this->company = Companies::factory()->create();
        $this->region = $this->createDefaultRegion($this->company, app(Apps::class), $user);
        $this->configureDhl($this->company);
        $this->connectDhlIntegration($this->company, $this->region);
        $this->setUpDhlOrderStatuses();
        app(Apps::class)->set(ShippingConfigurationEnum::DEFAULT_BOX_CM->value, ['length' => 30, 'width' => 20, 'height' => 10]);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        app(Apps::class)->del(ShippingConfigurationEnum::DEFAULT_BOX_CM->value);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testBookingStoresTheTrackingNumberLabelAndMovesTheOrderToDispatched(): void
    {
        $this->fakeDhl([self::DHL_HOST . '/shipments' => Http::response($this->dhlShipmentResponse(), 201)]);
        $documents = $this->fakeDhlDocuments();
        $order = $this->dhlOrder($this->company, 'ready-for-dispatch');

        $result = new CreateDhlShipmentAction($order)->execute();

        $order->refresh();
        $this->assertSame('success', $result['status']);
        $this->assertSame(self::DHL_AWB, $order->tracking_client_id);
        $this->assertNotNull($order->shipped_date);
        $this->assertSame('dispatched', $this->statusSlugOf($order));
        $this->assertSame(BookingStateEnum::BOOKED->value, $order->get(CustomFieldEnum::BOOKING_STATE->value));
        $this->assertSame('PRG-241110-0001', $order->get(CustomFieldEnum::DISPATCH_CONFIRMATION_NUMBER->value));
        $this->assertCount(1, $documents);
        $this->assertSame('dhl-label-' . self::DHL_AWB . '.pdf', $documents[0]['file']);
        $this->assertSame('dhl-label', $documents[0]['field']);
        $this->assertStringStartsWith('%PDF', $documents[0]['content']);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::DHL_HOST . '/shipments'
            && $request->hasHeader('Message-Reference', $order->uuid)
            && $request['productCode'] === 'N'
            && $request['valueAddedServices'] === [['serviceCode' => 'SD']]
            && $request['pickup']['isRequested'] === true
            && $request['customerDetails']['receiverDetails']['postalAddress']['postalCode'] === '51000'
            && $request['customerDetails']['receiverDetails']['postalAddress']['countryCode'] === 'DO'
            && $request['content']['isCustomsDeclarable'] === false
            && $request['outputImageProperties']['imageOptions'][0]['templateName'] === ConfigurationEnum::DEFAULT_LABEL_TEMPLATE);
    }

    public function testADocumentThatCannotBeStoredStillDispatchesAndEmailsTheCustomer(): void
    {
        $this->fakeDhl([self::DHL_HOST . '/shipments' => Http::response($this->dhlShipmentResponse(), 201)]);
        app()->bind(AttachDhlDocumentAction::class, fn (): AttachDhlDocumentAction => new class () extends AttachDhlDocumentAction {
            public function execute(
                Order $order,
                string $base64Content,
                string $fileName,
                string $field
            ): void {
                throw new RuntimeException('storage is down');
            }
        });
        $order = $this->dhlOrder($this->company, 'ready-for-dispatch');
        $order->people->addEmail('maria@customer.test');

        $result = new CreateDhlShipmentAction($order)->execute();

        $this->assertSame('success', $result['status']);
        $this->assertSame('dispatched', $this->statusSlugOf($order));
        $this->assertSame(BookingStateEnum::BOOKED->value, $order->get(CustomFieldEnum::BOOKING_STATE->value));
        Notification::assertSentOnDemand(
            OrderNotification::class,
            fn (OrderNotification $notification): bool => $notification->getTemplateName() === CreateDhlShipmentAction::CUSTOMER_TEMPLATE
        );
    }

    public function testThePreparingNoticeIsNotSentOnceTheShipmentIsBookedButIsWithoutABooking(): void
    {
        $booked = $this->dhlOrder($this->company, 'dispatched');
        $booked->people->addEmail('maria@customer.test');
        $booked->set(CustomFieldEnum::BOOKING_STATE->value, BookingStateEnum::BOOKED->value);

        $skipped = $this->activity(NotifyOrderStatusActivity::class)
            ->execute($booked, app(Apps::class), $this->transitionParams('ready-for-dispatch'));

        $this->assertSame('skipped', $skipped['status'] ?? json_encode($skipped));
        Notification::assertNothingSent();

        $manual = $this->dhlOrder($this->company, 'ready-for-dispatch', ['metadata' => ['shipping' => ['provider' => 'inposdom']]]);
        $manual->people->addEmail('maria@customer.test');

        $sent = $this->activity(NotifyOrderStatusActivity::class)
            ->execute($manual, app(Apps::class), $this->transitionParams('ready-for-dispatch'));

        $this->assertSame('success', $sent['status'] ?? json_encode($sent));
    }

    public function testAnOrderThatLeftReadyForDispatchIsNeverBooked(): void
    {
        $this->fakeDhl();
        $order = $this->dhlOrder($this->company, 'cancelled');

        $result = new CreateDhlShipmentAction($order)->execute();

        $this->assertSame('skipped', $result['status']);
        $this->assertNull($order->get(CustomFieldEnum::BOOKING_STATE->value));
        Http::assertNothingSent();
    }

    public function testABookingAlreadyRunningForTheOrderIsNotStartedTwice(): void
    {
        $this->fakeDhl();
        $order = $this->dhlOrder($this->company, 'ready-for-dispatch');
        $running = Cache::lock(CreateDhlShipmentAction::LOCK_PREFIX . $order->uuid, 60);
        $running->get();

        try {
            $result = new CreateDhlShipmentAction($order)->execute();
        } finally {
            $running->release();
        }

        $this->assertSame('skipped', $result['status']);
        Http::assertNothingSent();
    }

    public function testADhlServerErrorFailsTheWorkflowAndTellsTheOwnerToCheckMyDhl(): void
    {
        $this->fakeDhl([self::DHL_HOST . '/shipments' => Http::response([], 503)]);
        $order = $this->dhlOrder($this->company, 'ready-for-dispatch');

        $result = $this->runBooking($order, 'ready-for-dispatch');

        $this->assertSame(BookingStateEnum::PENDING->value, $order->get(CustomFieldEnum::BOOKING_STATE->value));
        $this->assertSame('ready-for-dispatch', $this->statusSlugOf($order));
        $this->assertStringContainsString('MyDHL', $result['message'] ?? json_encode($result));
        Notification::assertSentOnDemand(
            OrderNotification::class,
            fn (OrderNotification $notification): bool => $notification->getTemplateName() === CreateDhlShipmentActivity::OWNER_FAILURE_TEMPLATE
        );
    }

    public function testASecondRunDoesNotBookAgain(): void
    {
        $this->fakeDhlDocuments();
        $this->fakeDhl([self::DHL_HOST . '/shipments' => Http::response($this->dhlShipmentResponse(), 201)]);
        $order = $this->dhlOrder($this->company, 'ready-for-dispatch');

        new CreateDhlShipmentAction($order)->execute();
        $second = new CreateDhlShipmentAction($order->refresh())->execute();

        $this->assertSame('skipped', $second['status']);
        Http::assertSentCount(1);
    }

    public function testAPendingBookingWithoutATrackingNumberIsNeverRetried(): void
    {
        $this->fakeDhl();
        $order = $this->dhlOrder($this->company, 'ready-for-dispatch');
        $order->set(CustomFieldEnum::BOOKING_STATE->value, BookingStateEnum::PENDING->value);

        $this->expectException(DhlBookingException::class);

        try {
            new CreateDhlShipmentAction($order)->execute();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function testMissingConsigneeDataIsNamedAndNothingIsSent(): void
    {
        $this->fakeDhl();
        $order = $this->dhlOrder($this->company, 'ready-for-dispatch', withAddress: false);

        try {
            new CreateDhlShipmentAction($order)->execute();
            $this->fail('Expected a booking exception');
        } catch (DhlBookingException $exception) {
            $this->assertStringContainsString('address', $exception->getMessage());
            $this->assertStringNotContainsString('postal code', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertNull($order->get(CustomFieldEnum::BOOKING_STATE->value));
    }

    public function testAnInternationalBookingSendsTheExportDeclarationAndStoresTheCommercialInvoice(): void
    {
        $response = $this->dhlShipmentResponse();
        $response['documents'][] = ['imageFormat' => 'PDF', 'content' => base64_encode('%PDF-1.4 fake invoice'), 'typeCode' => 'invoice'];
        $this->fakeDhl([self::DHL_HOST . '/shipments' => Http::response($response, 201)]);
        $documents = $this->fakeDhlDocuments();
        $order = $this->internationalOrder([
            $this->orderLine('Robusto box', 60.0, 2, $this->customsVariant('Robusto', $this->completeCustomsData(), weight: 0.3)),
            $this->orderLine('Torpedo', 25.5, 1, $this->customsVariant('Torpedo', [
                CustomsAttributeEnum::DESCRIPTION->value => 'Cigars',
                CustomsAttributeEnum::MANUFACTURER_COUNTRY->value => 'NI',
            ], weight: 0.2)),
        ]);

        $result = new CreateDhlShipmentAction($order)->execute();

        $this->assertSame('success', $result['status']);
        $this->assertSame(['dhl-label', 'dhl-commercial-invoice'], array_column($documents->getArrayCopy(), 'field'));
        $this->assertSame('dhl-commercial-invoice-' . self::DHL_AWB . '.pdf', $documents[1]['file']);

        $body = Http::recorded()->first()[0]->data();
        $content = $body['content'];
        $lines = $content['exportDeclaration']['lineItems'];
        $this->assertTrue($content['isCustomsDeclarable']);
        $this->assertEquals(145.5, $content['declaredValue']);
        $this->assertSame('USD', $content['declaredValueCurrency']);
        $this->assertSame(ConfigurationEnum::DEFAULT_INCOTERM, $content['incoterm']);
        $this->assertSame((string) $order->order_number, $content['exportDeclaration']['invoice']['number']);
        $this->assertEquals([
            'number' => 1,
            'description' => 'Hand rolled cigars',
            'price' => 60.0,
            'quantity' => ['value' => 2, 'unitOfMeasurement' => 'PCS'],
            'commodityCodes' => [['typeCode' => 'outbound', 'value' => '240210']],
            'manufacturerCountry' => 'DO',
            'weight' => ['netValue' => 0.6, 'grossValue' => 0.6],
        ], $lines[0]);
        $this->assertArrayNotHasKey('commodityCodes', $lines[1]);
        $this->assertSame('NI', $lines[1]['manufacturerCountry']);
        $this->assertSame('P', $body['productCode']);
        $this->assertSame('invoice', $body['outputImageProperties']['imageOptions'][1]['typeCode']);
    }

    public function testAnInternationalBookingMissingCustomsDataNamesTheProductAndSendsNothing(): void
    {
        $this->fakeDhl();
        $order = $this->internationalOrder([
            $this->orderLine('Torpedo', 25.5, 1, $this->customsVariant('Torpedo', [CustomsAttributeEnum::DESCRIPTION->value => 'Cigars'], weight: 0)),
        ]);

        try {
            new CreateDhlShipmentAction($order)->execute();
            $this->fail('Expected a booking exception');
        } catch (DhlBookingException $exception) {
            $this->assertStringContainsString('Torpedo (manufacturer-country, weight)', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertNull($order->get(CustomFieldEnum::BOOKING_STATE->value));
    }

    #[DataProvider('plannedShippingTimes')]
    public function testThePlannedShippingTimeIsLocalAndNeverAfterThePickupCloses(string $nowUtc, string $expected): void
    {
        Carbon::setTestNow(Carbon::parse($nowUtc, 'UTC'));
        $this->company->timezone = '';
        $this->company->saveQuietly();
        $this->fakeDhlDocuments();
        $this->fakeDhl([self::DHL_HOST . '/shipments' => Http::response($this->dhlShipmentResponse(), 201)]);
        $order = $this->dhlOrder($this->company, 'ready-for-dispatch');

        new CreateDhlShipmentAction($order)->execute();

        Http::assertSent(fn (Request $request): bool => $request->url() === self::DHL_HOST . '/shipments'
            && $request['plannedShippingDateAndTime'] === $expected);
    }

    public function testAPickupPastTheLocationCutoffIsRebookedForTheNextBusinessDayOnce(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-12 17:06:00', 'UTC'));
        $this->company->timezone = 'America/Santo_Domingo';
        $this->company->saveQuietly();
        $this->fakeDhlDocuments();
        $this->fakeDhl([
            self::DHL_HOST . '/shipments' => Http::sequence()
                ->push([
                    'detail' => 'Multiple problems found, see Additional Details',
                    'additionalDetails' => [
                        '5001: The pickup request is past the booking cutoff time for the pickup location.',
                        '5005: Bookings for this location must be created before 13 November 2026, by 12:00 (origin time).',
                    ],
                ], 422)
                ->push($this->dhlShipmentResponse(), 201),
        ]);
        $order = $this->dhlOrder($this->company, 'ready-for-dispatch');

        $result = new CreateDhlShipmentAction($order)->execute();

        $this->assertSame('success', $result['status']);
        $this->assertSame('dispatched', $this->statusSlugOf($order->refresh()));
        $planned = [];
        Http::assertSent(function (Request $request) use (&$planned): bool {
            $planned[] = $request['plannedShippingDateAndTime'];

            return true;
        });
        $this->assertSame(['2026-11-12T14:06:00 GMT-04:00', '2026-11-13T09:00:00 GMT-04:00'], $planned);
    }

    public function testAnyOtherRejectionIsNotRetried(): void
    {
        $this->fakeDhl([
            self::DHL_HOST . '/shipments' => Http::response([
                'detail' => 'Multiple problems found, see Additional Details',
                'additionalDetails' => ['420504: The requested product(s) (N) not available.'],
            ], 422),
        ]);
        $order = $this->dhlOrder($this->company, 'ready-for-dispatch');

        try {
            new CreateDhlShipmentAction($order)->execute();
            $this->fail('Expected a booking exception');
        } catch (DhlBookingException $exception) {
            $this->assertStringContainsString('420504', $exception->getMessage());
        }

        Http::assertSentCount(1);
        $this->assertNull($order->get(CustomFieldEnum::BOOKING_STATE->value));
    }

    public static function plannedShippingTimes(): array
    {
        return [
            'thursday afternoon in Santo Domingo' => ['2026-11-12 17:06:00', '2026-11-12T14:06:00 GMT-04:00'],
            'after the 18:00 close' => ['2026-11-12 21:30:00', '2026-11-13T09:00:00 GMT-04:00'],
            'friday evening rolls to monday' => ['2026-11-13 22:30:00', '2026-11-16T09:00:00 GMT-04:00'],
            'saturday rolls to monday' => ['2026-11-14 14:00:00', '2026-11-16T09:00:00 GMT-04:00'],
        ];
    }

    public function testAnAddressWithoutPostalCodeBooksWithAnEmptyOne(): void
    {
        $this->fakeDhlDocuments();
        $this->fakeDhl([self::DHL_HOST . '/shipments' => Http::response($this->dhlShipmentResponse(), 201)]);
        $origin = $this->company->get(ConfigurationEnum::ORIGIN->value);
        unset($origin['postal_code']);
        $this->company->set(ConfigurationEnum::ORIGIN->value, $origin);
        $order = $this->dhlOrder($this->company, 'ready-for-dispatch');
        $order->shippingAddress->update(['zip' => '']);

        $result = new CreateDhlShipmentAction($order->refresh())->execute();

        $this->assertSame('success', $result['status']);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::DHL_HOST . '/shipments'
            && $request['customerDetails']['shipperDetails']['postalAddress']['postalCode'] === ''
            && $request['customerDetails']['receiverDetails']['postalAddress']['postalCode'] === '');
    }

    public function testADhlClientErrorReleasesTheReservationButAServerErrorKeepsIt(): void
    {
        $rejected = $this->dhlOrder($this->company, 'ready-for-dispatch');
        $this->fakeDhl([self::DHL_HOST . '/shipments' => Http::sequence()
            ->push(['detail' => 'Invalid postal code'], 422)
            ->push([], 503)]);

        try {
            new CreateDhlShipmentAction($rejected)->execute();
            $this->fail('Expected a rejection');
        } catch (DhlBookingException $exception) {
            $this->assertStringContainsString('Invalid postal code', $exception->getMessage());
        }

        $this->assertNull($rejected->get(CustomFieldEnum::BOOKING_STATE->value));

        $unknown = $this->dhlOrder($this->company, 'ready-for-dispatch');

        try {
            new CreateDhlShipmentAction($unknown)->execute();
            $this->fail('Expected the server error to surface');
        } catch (RequestException) {
            $this->assertSame(BookingStateEnum::PENDING->value, $unknown->get(CustomFieldEnum::BOOKING_STATE->value));
        }
    }

    public function testAChangedSignatureConfigurationRefusesToReplayTheQuote(): void
    {
        $this->fakeDhl();
        $this->company->set(ConfigurationEnum::SIGNATURE_SERVICE->value, 'SF');
        $order = $this->dhlOrder($this->company, 'ready-for-dispatch');

        $this->expectException(DhlBookingException::class);
        $this->expectExceptionMessage('SF');

        try {
            new CreateDhlShipmentAction($order)->execute();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function testAnOrderNotQuotedWithDhlIsSkipped(): void
    {
        $this->fakeDhl();
        $order = $this->dhlOrder($this->company, 'ready-for-dispatch', ['metadata' => ['shipping' => ['provider' => 'inposdom']]]);

        $result = new CreateDhlShipmentAction($order)->execute();

        $this->assertSame('skipped', $result['status']);
        Http::assertNothingSent();
    }

    public function testTheActivityBooksOnReadyForDispatchAndIgnoresOtherTransitions(): void
    {
        $this->fakeDhlDocuments();
        $this->fakeDhl([self::DHL_HOST . '/shipments' => Http::response($this->dhlShipmentResponse(), 201)]);
        $order = $this->dhlOrder($this->company, 'ready-for-dispatch');

        $ignored = $this->runBooking($order, 'paid');
        $booked = $this->runBooking($order, 'ready-for-dispatch');

        $this->assertSame('skipped', $ignored['status'] ?? json_encode($ignored));
        $this->assertSame('success', $booked['status'] ?? json_encode($booked));
        Http::assertSentCount(1);
    }

    public function testTheActivityFlagsFailureWithoutThrowingWhenDataIsMissing(): void
    {
        $this->fakeDhl();
        $order = $this->dhlOrder($this->company, 'ready-for-dispatch', withAddress: false);

        $activity = $this->activity(CreateDhlShipmentActivity::class);
        $result = $activity->execute($order, app(Apps::class), $this->transitionParams('ready-for-dispatch'));

        $this->assertStringContainsString('missing', $result['message']);
        $this->assertSame(StatusEnum::FAILED, $activity->workflowStatus());
        Http::assertNothingSent();
    }

    public function testCancellingAfterBookingFlagsManualReviewAndAnUnbookedCancelDoesNot(): void
    {
        $this->fakeDhl();
        $booked = $this->dhlOrder($this->company, 'cancelled');
        $booked->set(CustomFieldEnum::BOOKING_STATE->value, BookingStateEnum::BOOKED->value);
        $unbooked = $this->dhlOrder($this->company, 'cancelled');

        $this->activity(NotifyOrderStatusActivity::class)
            ->execute($booked, app(Apps::class), $this->transitionParams('cancelled'));
        $this->activity(NotifyOrderStatusActivity::class)
            ->execute($unbooked, app(Apps::class), $this->transitionParams('cancelled'));

        $this->assertSame(BookingStateEnum::MANUAL_REVIEW->value, $booked->get(CustomFieldEnum::BOOKING_STATE->value));
        $this->assertNull($unbooked->get(CustomFieldEnum::BOOKING_STATE->value));
    }

    #[DataProvider('statusNotices')]
    public function testEachStatusEmailsTheCustomerAndTheOwnerItConcerns(string $toStatus, array $expected): void
    {
        $order = $this->dhlOrder($this->company, $toStatus);
        $order->people->addEmail('maria@customer.test');

        $this->activity(NotifyOrderStatusActivity::class)
            ->execute($order, app(Apps::class), $this->transitionParams($toStatus));

        if ($expected === []) {
            Notification::assertNothingSent();

            return;
        }

        $sent = [];
        Notification::assertSentOnDemand(OrderNotification::class, function (OrderNotification $notification) use (&$sent): bool {
            $sent[] = $notification->getTemplateName();

            return true;
        });

        $this->assertEqualsCanonicalizing($expected, $sent);
    }

    public static function statusNotices(): array
    {
        return [
            'placed' => ['verifying', ['user-order-verifying', 'owner-order-verifying']],
            'confirmed' => ['paid', ['user-order-paid']],
            'preparing' => ['ready-for-dispatch', ['user-order-ready-for-dispatch']],
            'shipped is sent by the booking' => ['dispatched', []],
            'delivered' => ['delivered', ['user-order-delivered', 'owner-order-delivered']],
            'returned' => ['returned', ['user-order-returned', 'owner-order-returned']],
            'out of stock' => ['out-of-stock', ['user-order-out-of-stock']],
            'cancelled' => ['cancelled', ['user-order-cancelled', 'owner-order-cancelled']],
        ];
    }

    private function runBooking(Order $order, string $toStatus): array
    {
        return $this->activity(CreateDhlShipmentActivity::class)
            ->execute($order, app(Apps::class), $this->transitionParams($toStatus));
    }

    private function transitionParams(string $toStatus): array
    {
        return [
            'currentEventTypeName' => WorkflowEnum::STATUS_TRANSITION->value,
            'to_status' => $toStatus,
        ];
    }

    private function activity(string $class): object
    {
        return new $class(0, now()->toDateTimeString(), StoredWorkflow::make(), []);
    }

    private function internationalOrder(array $items): Order
    {
        $order = $this->dhlOrder($this->company, 'ready-for-dispatch', [
            'metadata' => ['shipping' => ['currency' => 'USD'] + $this->dhlShippingMetadata(['product_code' => 'P', 'local_product_code' => 'P'])],
        ]);
        $order->shippingAddress->update([
            'address' => '100 Biscayne Blvd',
            'city' => 'Miami',
            'state' => 'FL',
            'zip' => '33131',
            'countries_id' => Countries::firstOrCreate(['code' => 'us'], ['name' => 'United States'])->getId(),
        ]);
        $order->setRelation('items', collect($items));

        return $order;
    }

    private function orderLine(string $name, float $price, int $quantity, Variants $variant): OrderItem
    {
        $item = new OrderItem();
        $item->forceFill(['product_name' => $name, 'quantity' => $quantity, 'unit_price_net_amount' => $price]);
        $item->setRelation('variant', $variant);

        return $item;
    }
}
