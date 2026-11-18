<?php

declare(strict_types=1);

namespace Tests\Connectors\Movipass;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\AcceptRoadsideOfferAction;
use Kanvas\Connectors\Movipass\Actions\AddRoadsideEvidenceAction;
use Kanvas\Connectors\Movipass\Actions\CloseRoadsideServiceAction;
use Kanvas\Connectors\Movipass\Actions\DispatchRegionalRoadsideCaseAction;
use Kanvas\Connectors\Movipass\Actions\RecordRoadsideTrackingAction;
use Kanvas\Connectors\Movipass\Actions\RejectRoadsideOfferAction;
use Kanvas\Connectors\Movipass\Actions\ResolveRoadsideCabinReviewAction;
use Kanvas\Connectors\Movipass\Actions\StartRoadsideServiceWithPinAction;
use Kanvas\Connectors\Movipass\Actions\SyncRoadsideAssistanceStatusesAction;
use Kanvas\Connectors\Movipass\Actions\TransitionRoadsideCaseStateAction;
use Kanvas\Connectors\Movipass\Actions\UpdateRoadsideTariffsAction;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\MechanicAvailabilityEnum;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideCaseStateEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideServiceTypeEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideTechnicianDeclineReasonEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideZoneEnum;
use Kanvas\Connectors\Movipass\Jobs\ExpireRoadsideOfferJob;
use Kanvas\Connectors\Movipass\Services\RoadsideAuditTrail;
use Kanvas\Connectors\Movipass\Support\RegionalAssistance;
use Kanvas\Connectors\Movipass\Support\RoadsideIntakeRules;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Users\Models\Users;
use PHPUnit\Framework\Attributes\Group;
use Tests\Connectors\Movipass\Concerns\BuildsRoadsideIntake;
use Tests\Connectors\Movipass\Concerns\TogglesRegionalAssistance;
use Tests\TestCase;

/**
 * The Asistencia Regional case from payment to close: dispatch only once paid, the cabin hold, the
 * offer round and its first-accept, tracking into proximity and arrival, the PIN gate, evidence and
 * the final close, all on the homologated state machine and its audit trail.
 */
#[Group('serial')]
final class RoadsideRegionalFlowTest extends TestCase
{
    use BuildsRoadsideIntake;
    use TogglesRegionalAssistance;

    // Kilómetro Cero, San Salvador.
    private const USER_LOCATION = ['lat' => 13.6977, 'lng' => -89.1910];

    protected Apps $apps;
    protected Users $technician;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Queue::fake();

        $this->apps = app(Apps::class);
        $this->technician = auth()->user();
        $this->technician->set(CustomFieldEnum::MECHANIC_AVAILABILITY->value, MechanicAvailabilityEnum::ACTIVO->value);
        $this->technician->set(CustomFieldEnum::MECHANIC_SERVICE_TYPE->value, '');

        $this->rememberRegionalAssistanceSettings($this->apps);
        $this->toggleRegionalAssistance($this->apps, true);

        new SyncRoadsideAssistanceStatusesAction($this->apps)->execute();
    }

    protected function tearDown(): void
    {
        $this->restoreRegionalAssistanceSettings($this->apps);

        parent::tearDown();
    }

    public function testAnUnpaidCaseIsNeverDispatched(): void
    {
        $order = $this->createCase(paid: false);

        $this->assertFalse(new DispatchRegionalRoadsideCaseAction($order)->execute());
        $this->assertArrayNotHasKey('state', $order->refresh()->metadata['assistance_case']);
        Queue::assertNotPushed(ExpireRoadsideOfferJob::class);
    }

    public function testAPaidCaseIsOfferedOnceAndTheFirstAcceptTakesIt(): void
    {
        $order = $this->createCase();

        $this->assertTrue(new DispatchRegionalRoadsideCaseAction($order)->execute());
        $this->assertFalse(new DispatchRegionalRoadsideCaseAction($order->refresh())->execute(), 'dispatch must be idempotent');

        $case = $order->refresh()->metadata['assistance_case'];
        $this->assertSame(RoadsideCaseStateEnum::PENDING_ASSIGNMENT->value, $case['state']);
        $this->assertContains($this->technician->getId(), $case['offer']['offered_ids']);
        $this->assertSame(['CREATED', 'PENDING_ASSIGNMENT'], array_column($case['state_history'], 'to'));
        Queue::assertPushed(ExpireRoadsideOfferJob::class);

        new AcceptRoadsideOfferAction($order, $this->technician)->execute();

        $order->refresh();
        $this->assertSame(MovipassOrderStatusEnum::DISPATCHED->slug(), $order->orderStatus?->slug);
        $this->assertSame(RoadsideCaseStateEnum::TECHNICIAN_ASSIGNED->value, $order->metadata['assistance_case']['state']);
        $this->assertGreaterThan(0, $this->auditCount($order, 'technician', 'offer_accepted'));
    }

    public function testACabinReviewCaseWaitsForTheCabinBeforeAnyOffer(): void
    {
        $order = $this->createCase(intake: ['police_report_exists' => false], service: RoadsideServiceTypeEnum::ACCIDENT_TOW);

        new DispatchRegionalRoadsideCaseAction($order)->execute();

        $this->assertSame(RoadsideCaseStateEnum::CABIN_REVIEW->value, $order->refresh()->metadata['assistance_case']['state']);
        Queue::assertNotPushed(ExpireRoadsideOfferJob::class);

        new ResolveRoadsideCabinReviewAction($order, $this->technician, approved: true)->execute();

        $this->assertSame(RoadsideCaseStateEnum::PENDING_ASSIGNMENT->value, $order->refresh()->metadata['assistance_case']['state']);
    }

    public function testRejectingNeedsACommentForOtherAndKeepsTheTechnicianOffTheCase(): void
    {
        $order = $this->dispatched();

        try {
            new RejectRoadsideOfferAction($order, $this->technician, RoadsideTechnicianDeclineReasonEnum::OTHER)->execute();
            $this->fail('Rejecting with "other" and no comment must be refused');
        } catch (ValidationException) {
        }

        new RejectRoadsideOfferAction($order, $this->technician, RoadsideTechnicianDeclineReasonEnum::UNIT_UNAVAILABLE)->execute();

        $case = $order->refresh()->metadata['assistance_case'];
        $this->assertContains($this->technician->getId(), $case['rejected_mechanic_ids']);
        $this->assertNotContains($this->technician->getId(), $case['offer']['offered_ids']);

        $this->expectException(ValidationException::class);
        new AcceptRoadsideOfferAction($order, $this->technician)->execute();
    }

    public function testTrackingMovesTheCaseEnRouteThenNearbyThenOnSite(): void
    {
        $order = $this->accepted();

        $far = new RecordRoadsideTrackingAction($order, $this->technician, 13.7200, -89.2100)->execute();
        $this->assertSame(RoadsideCaseStateEnum::EN_ROUTE->value, $far['state']);

        // ~200 m north of the user: inside the proximity radius, outside the arrival threshold.
        $near = new RecordRoadsideTrackingAction($order->refresh(), $this->technician, 13.6995, -89.1910)->execute();
        $this->assertSame(RoadsideCaseStateEnum::NEARBY->value, $near['state']);

        $here = new RecordRoadsideTrackingAction($order->refresh(), $this->technician, ...array_values(self::USER_LOCATION))->execute();
        $this->assertSame(RoadsideCaseStateEnum::ON_SITE->value, $here['state']);
        $this->assertSame(MovipassOrderStatusEnum::ON_SITE->slug(), $order->refresh()->orderStatus?->slug);
        $this->assertCount(3, $order->metadata['assistance_case']['tracking']['route']);
    }

    public function testAWrongPinIsRecordedAndDoesNotStartTheService(): void
    {
        $order = $this->onSite();

        try {
            new StartRoadsideServiceWithPinAction($order, '0000', $this->technician)->execute();
        } catch (ValidationException) {
        }

        $order->refresh();
        $this->assertSame(MovipassOrderStatusEnum::ON_SITE->slug(), $order->orderStatus?->slug);
        $this->assertFalse($order->metadata['assistance_case']['pin_attempts'][0]['valid']);
        $this->assertGreaterThan(0, $this->auditCount($order, 'order', 'pin_rejected'));

        new StartRoadsideServiceWithPinAction($order, (string) $order->get(CustomFieldEnum::ROADSIDE_ASSISTANCE_PIN->value), $this->technician)->execute();

        $this->assertSame(MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->slug(), $order->refresh()->orderStatus?->slug);
    }

    public function testTheStateMachineRefusesToStartWithoutAValidatedPinAndAuditsTheAttempt(): void
    {
        $order = $this->onSite();

        try {
            new TransitionRoadsideCaseStateAction($order, RoadsideCaseStateEnum::IN_PROGRESS)->execute();
            $this->fail('IN_PROGRESS without a validated PIN must be refused');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('PIN', $exception->getMessage());
        }

        $this->assertGreaterThan(0, $this->auditCount($order, 'order', 'state_change_rejected'));
    }

    public function testEvidenceOpensWithThePinAndTheCloseNeedsAllOfIt(): void
    {
        $order = $this->onSite();

        try {
            new AddRoadsideEvidenceAction($order, $this->technician, comment: 'Arrived')->execute();
        } catch (ValidationException) {
            $this->fail('The operative comment does not wait for the PIN');
        }

        new StartRoadsideServiceWithPinAction($order, (string) $order->get(CustomFieldEnum::ROADSIDE_ASSISTANCE_PIN->value), $this->technician)->execute();
        new TransitionRoadsideCaseStateAction($order->refresh(), RoadsideCaseStateEnum::IN_PROGRESS)->execute();

        try {
            new CloseRoadsideServiceAction($order->refresh(), $this->technician, resolved: true)->execute();
            $this->fail('Closing without the photos must be refused');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('before photos', $exception->getMessage());
        }

        $this->setCase($order, ['evidence' => [
            ['phase' => 'before'], ['phase' => 'before'], ['phase' => 'after'], ['phase' => 'after'],
        ]]);

        new CloseRoadsideServiceAction($order->refresh(), $this->technician, resolved: true)->execute();

        $order->refresh();
        $this->assertSame(MovipassOrderStatusEnum::SERVICE_COMPLETED->slug(), $order->orderStatus?->slug);
        $this->assertSame(RoadsideCaseStateEnum::COMPLETED->value, $order->metadata['assistance_case']['state']);
    }

    public function testATariffVersionOnlyPricesWhatIsCreatedAfterItsEffectiveDate(): void
    {
        $effective = Carbon::now()->addDay();

        new UpdateRoadsideTariffsAction(
            app: $this->apps,
            company: null,
            changedBy: $this->technician,
            tariffs: ['services' => ['jump_start' => ['amss' => 18.00, 'other_city' => 20.00, 'foraneo' => 35.00, 'per_km' => null]]],
            reason: 'Fuel cost increase',
            effectiveFrom: $effective,
        )->execute();

        $jumpStart = RoadsideServiceTypeEnum::JUMP_START;
        $amss = RoadsideZoneEnum::AMSS;

        $this->assertSame(15.00, RegionalAssistance::tariffs($this->apps, null, Carbon::now())->baseFare($jumpStart, $amss));
        $this->assertSame(18.00, RegionalAssistance::tariffs($this->apps, null, $effective->copy()->addMinute())->baseFare($jumpStart, $amss));

        $this->expectException(ValidationException::class);
        new UpdateRoadsideTariffsAction(
            app: $this->apps,
            company: null,
            changedBy: $this->technician,
            tariffs: [],
            reason: '   ',
        )->execute();
    }

    private function dispatched(): Order
    {
        $order = $this->createCase();
        new DispatchRegionalRoadsideCaseAction($order)->execute();

        return $order->refresh();
    }

    private function accepted(): Order
    {
        $order = $this->dispatched();

        return new AcceptRoadsideOfferAction($order, $this->technician)->execute()->refresh();
    }

    private function onSite(): Order
    {
        $order = $this->accepted();
        new RecordRoadsideTrackingAction($order, $this->technician, ...array_values(self::USER_LOCATION))->execute();

        return $order->refresh();
    }

    private function createCase(
        bool $paid = true,
        array $intake = [],
        RoadsideServiceTypeEnum $service = RoadsideServiceTypeEnum::JUMP_START
    ): Order {
        $orderType = OrderTypes::where('apps_id', $this->apps->getId())
            ->where('name', OrderTypeEnum::ROADSIDE_ASSISTANCE->value)
            ->orderBy('id')
            ->firstOrFail();

        $verdict = RoadsideIntakeRules::evaluate($service, $this->proceedingIntake($service, $intake));

        $order = Order::factory()
            ->withCompanyId($this->technician->getCurrentCompany()->getId())
            ->withUserId($this->technician->getId())
            ->create([
                'order_types_id' => $orderType->getId(),
                'payment_status' => $paid ? PaymentStatusEnum::PAID->value : null,
                'metadata' => ['assistance_case' => [
                    'service' => $service->label(),
                    'service_type' => $service->value,
                    'location' => self::USER_LOCATION,
                    'intake' => $this->proceedingIntake($service, $intake),
                    'intake_verdict' => $verdict->toArray(),
                    'terms_accepted' => true,
                    'prepared_at' => Carbon::now()->toISOString(),
                ]],
            ]);

        $order->order_status_id = OrderStatus::where('order_types_id', $orderType->getId())
            ->where('slug', MovipassOrderStatusEnum::REQUEST_SUBMITTED->slug())
            ->firstOrFail()
            ->getId();
        $order->saveQuietly();

        return $order->refresh();
    }

    private function setCase(Order $order, array $values): void
    {
        $metadata = $order->metadata;
        $metadata['assistance_case'] = [...$metadata['assistance_case'], ...$values];
        $metadata['data']['assistance_case'] = $metadata['assistance_case'];
        $order->metadata = $metadata;
        $order->saveQuietly();
    }

    private function auditCount(Order $order, string $module, string $action): int
    {
        return Event::query()
            ->where('source_domain', RoadsideAuditTrail::SOURCE_DOMAIN)
            ->where('source_entity_type', RoadsideAuditTrail::ORDER_ENTITY)
            ->where('source_entity_id', $order->getId())
            ->where('event_type', RoadsideAuditTrail::SOURCE_DOMAIN . '.' . $module . '.' . $action)
            ->count();
    }
}
