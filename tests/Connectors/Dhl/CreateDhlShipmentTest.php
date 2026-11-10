<?php

declare(strict_types=1);

namespace Tests\Connectors\Dhl;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Dhl\Actions\CreateDhlShipmentAction;
use Kanvas\Connectors\Dhl\Activities\CreateDhlShipmentActivity;
use Kanvas\Connectors\Dhl\Activities\NotifyCabinetOrderStatusActivity;
use Kanvas\Connectors\Dhl\Enums\BookingStateEnum;
use Kanvas\Connectors\Dhl\Enums\ConfigurationEnum;
use Kanvas\Connectors\Dhl\Enums\CustomFieldEnum;
use Kanvas\Connectors\Dhl\Exceptions\DhlBookingException;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Shipping\Enums\ConfigurationEnum as ShippingConfigurationEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use PHPUnit\Framework\Attributes\Group;
use Tests\Connectors\Traits\CreatesDhlCabinetOrder;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

#[Group('serial')]
final class CreateDhlShipmentTest extends TestCase
{
    use CreatesDhlCabinetOrder;
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
        $this->setUpCabinetStatuses();
        app(Apps::class)->set(ShippingConfigurationEnum::DEFAULT_BOX_CM->value, ['length' => 30, 'width' => 20, 'height' => 10]);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        app(Apps::class)->del(ShippingConfigurationEnum::DEFAULT_BOX_CM->value);

        parent::tearDown();
    }

    public function testBookingStoresTheTrackingNumberLabelAndMovesTheOrderToDispatched(): void
    {
        $this->fakeDhl([self::DHL_HOST . '/shipments' => Http::response($this->dhlShipmentResponse(), 201)]);
        $documents = $this->fakeDhlDocuments();
        $order = $this->cabinetOrder($this->company, 'ready-for-dispatch');

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

    public function testASecondRunDoesNotBookAgain(): void
    {
        $this->fakeDhlDocuments();
        $this->fakeDhl([self::DHL_HOST . '/shipments' => Http::response($this->dhlShipmentResponse(), 201)]);
        $order = $this->cabinetOrder($this->company, 'ready-for-dispatch');

        new CreateDhlShipmentAction($order)->execute();
        $second = new CreateDhlShipmentAction($order->refresh())->execute();

        $this->assertSame('skipped', $second['status']);
        Http::assertSentCount(1);
    }

    public function testAPendingBookingWithoutATrackingNumberIsNeverRetried(): void
    {
        $this->fakeDhl();
        $order = $this->cabinetOrder($this->company, 'ready-for-dispatch');
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
        $order = $this->cabinetOrder($this->company, 'ready-for-dispatch', withAddress: false);

        try {
            new CreateDhlShipmentAction($order)->execute();
            $this->fail('Expected a booking exception');
        } catch (DhlBookingException $exception) {
            $this->assertStringContainsString('address', $exception->getMessage());
            $this->assertStringContainsString('postal code', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertNull($order->get(CustomFieldEnum::BOOKING_STATE->value));
    }

    public function testADhlClientErrorReleasesTheReservationButAServerErrorKeepsIt(): void
    {
        $rejected = $this->cabinetOrder($this->company, 'ready-for-dispatch');
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

        $unknown = $this->cabinetOrder($this->company, 'ready-for-dispatch');

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
        $order = $this->cabinetOrder($this->company, 'ready-for-dispatch');

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
        $order = $this->cabinetOrder($this->company, 'ready-for-dispatch', ['metadata' => ['shipping' => ['provider' => 'inposdom']]]);

        $result = new CreateDhlShipmentAction($order)->execute();

        $this->assertSame('skipped', $result['status']);
        Http::assertNothingSent();
    }

    public function testTheActivityBooksOnReadyForDispatchAndIgnoresOtherTransitions(): void
    {
        $this->fakeDhlDocuments();
        $this->fakeDhl([self::DHL_HOST . '/shipments' => Http::response($this->dhlShipmentResponse(), 201)]);
        $order = $this->cabinetOrder($this->company, 'ready-for-dispatch');

        $ignored = $this->runBooking($order, 'paid');
        $booked = $this->runBooking($order, 'ready-for-dispatch');

        $this->assertSame('skipped', $ignored['status'] ?? json_encode($ignored));
        $this->assertSame('success', $booked['status'] ?? json_encode($booked));
        Http::assertSentCount(1);
    }

    public function testTheActivityFlagsFailureWithoutThrowingWhenDataIsMissing(): void
    {
        $this->fakeDhl();
        $order = $this->cabinetOrder($this->company, 'ready-for-dispatch', withAddress: false);

        $activity = $this->activity(CreateDhlShipmentActivity::class);
        $result = $activity->execute($order, app(Apps::class), $this->transitionParams('ready-for-dispatch'));

        $this->assertStringContainsString('missing', $result['message']);
        $this->assertSame(StatusEnum::FAILED, $activity->workflowStatus());
        Http::assertNothingSent();
    }

    public function testCancellingAfterBookingFlagsManualReviewAndAnUnbookedCancelDoesNot(): void
    {
        $this->fakeDhl();
        $booked = $this->cabinetOrder($this->company, 'dispatched');
        $booked->set(CustomFieldEnum::BOOKING_STATE->value, BookingStateEnum::BOOKED->value);
        $unbooked = $this->cabinetOrder($this->company, 'paid');

        $this->activity(NotifyCabinetOrderStatusActivity::class)
            ->execute($booked, app(Apps::class), $this->transitionParams('cancelled'));
        $this->activity(NotifyCabinetOrderStatusActivity::class)
            ->execute($unbooked, app(Apps::class), $this->transitionParams('cancelled'));

        $this->assertSame(BookingStateEnum::MANUAL_REVIEW->value, $booked->get(CustomFieldEnum::BOOKING_STATE->value));
        $this->assertNull($unbooked->get(CustomFieldEnum::BOOKING_STATE->value));
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
}
