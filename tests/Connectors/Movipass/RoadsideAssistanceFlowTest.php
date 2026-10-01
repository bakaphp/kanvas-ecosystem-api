<?php

declare(strict_types=1);

namespace Tests\Connectors\Movipass;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideNotProceedReasonEnum;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Connectors\Movipass\Workflows\Activities\SyncMovipassRoadsideAssistanceActivity;
use Kanvas\Souk\Orders\Actions\CreateOrderStatusesAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

/**
 * The three flow branches the case lifecycle gained: the authorization gate that declines a case
 * before dispatch, the incident record, and the reschedule/close decision that follows it.
 */
final class RoadsideAssistanceFlowTest extends TestCase
{
    use HasIntegrationCompany;

    protected Apps $apps;
    protected Users $authUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->authUser = auth()->user();

        $this->createRoadsideStatuses();
    }

    public function testDecliningAtTheAuthorizationGateClosesTheCaseAsNotAuthorized(): void
    {
        $order = $this->createRoadsideOrder([
            'not_authorized' => true,
            'not_authorized_reason' => RoadsideNotProceedReasonEnum::OUT_OF_ZONE->value,
            'not_authorized_notes' => 'The client is outside the covered service area',
        ]);

        $result = $this->runUpdate($order);

        $this->assertSame('success', $result['status']);

        $order->refresh();
        $this->assertSame(
            MovipassOrderStatusEnum::SERVICE_NOT_AUTHORIZED->slug(),
            $order->orderStatus?->slug,
        );

        $assistanceCase = $order->metadata['assistance_case'];
        $this->assertSame(RoadsideNotProceedReasonEnum::OUT_OF_ZONE->value, $assistanceCase['not_authorized_reason']);
        $this->assertNotNull($assistanceCase['not_authorized_at']);
        $this->assertNull($assistanceCase['pin_hash']);
        // The flag is consumed so a later update event cannot re-run the decision.
        $this->assertArrayNotHasKey('not_authorized', $assistanceCase);
    }

    public function testDecliningWithAnUnknownReasonIsRejectedWithoutTouchingTheOrder(): void
    {
        $order = $this->createRoadsideOrder([
            'not_authorized' => true,
            'not_authorized_reason' => 'not_a_real_reason',
        ]);

        $result = $this->runUpdate($order);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('not_authorized_reason must be one of', $result['message']);

        $order->refresh();
        $this->assertNotSame(
            MovipassOrderStatusEnum::SERVICE_NOT_AUTHORIZED->slug(),
            $order->orderStatus?->slug,
        );
    }

    public function testACaseAlreadyOnTheRoadCannotBeDeclinedAtTheGate(): void
    {
        $order = $this->walkTo(
            $this->createRoadsideOrder(),
            MovipassOrderStatusEnum::AWAITING_OPERATOR,
            MovipassOrderStatusEnum::PROVIDER_ASSIGNED,
            MovipassOrderStatusEnum::DISPATCHED,
        );

        $this->setAssistanceCase($order, [
            'not_authorized' => true,
            'not_authorized_reason' => RoadsideNotProceedReasonEnum::REJECTED->value,
        ]);

        $result = $this->runUpdate($order);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('cancel it instead', $result['message']);

        $order->refresh();
        $this->assertSame(MovipassOrderStatusEnum::DISPATCHED->slug(), $order->orderStatus?->slug);
    }

    public function testRegisteringAnIncidentParksTheCaseOnTheRescheduleDecision(): void
    {
        $order = $this->walkTo(
            $this->createRoadsideOrder(),
            MovipassOrderStatusEnum::AWAITING_OPERATOR,
            MovipassOrderStatusEnum::PROVIDER_ASSIGNED,
            MovipassOrderStatusEnum::DISPATCHED,
            MovipassOrderStatusEnum::ON_SITE,
            MovipassOrderStatusEnum::SERVICE_IN_PROGRESS,
        );

        $this->setAssistanceCase($order, [
            'register_incident' => true,
            'incident_reason' => 'The tow truck could not maneuver in the parking garage',
        ]);

        $result = $this->runUpdate($order);

        $this->assertSame('success', $result['status']);

        $order->refresh();
        $assistanceCase = $order->metadata['assistance_case'];

        $this->assertCount(1, $assistanceCase['incidents']);
        $this->assertSame('The tow truck could not maneuver in the parking garage', $assistanceCase['incidents'][0]['reason']);
        $this->assertNull($assistanceCase['incidents'][0]['rescheduled_at']);
        $this->assertTrue($assistanceCase['pending_reschedule_decision']);
        // The case is still open — the decision has not been made yet.
        $this->assertSame(
            MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->slug(),
            $order->orderStatus?->slug,
        );
    }

    public function testAnIncidentWithoutAReasonIsRejected(): void
    {
        $order = $this->createRoadsideOrder([
            'register_incident' => true,
            'incident_reason' => '   ',
        ]);

        $result = $this->runUpdate($order);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('incident reason is required', $result['message']);
    }

    public function testReschedulingWithoutAnIncidentIsRejected(): void
    {
        $order = $this->createRoadsideOrder(['reschedule' => true]);

        $result = $this->runUpdate($order);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('no incident is awaiting a reschedule decision', $result['message']);
    }

    public function testReschedulingReturnsTheCaseToTheAssignmentPool(): void
    {
        $order = $this->walkTo(
            $this->createRoadsideOrder(),
            MovipassOrderStatusEnum::AWAITING_OPERATOR,
            MovipassOrderStatusEnum::PROVIDER_ASSIGNED,
            MovipassOrderStatusEnum::DISPATCHED,
            MovipassOrderStatusEnum::ON_SITE,
            MovipassOrderStatusEnum::SERVICE_IN_PROGRESS,
        );

        $this->setAssistanceCase($order, [
            'pending_reschedule_decision' => true,
            'incidents' => [[
                'reason' => 'No access to the vehicle',
                'registered_at' => now()->toISOString(),
                'rescheduled_at' => null,
            ]],
            'reschedule' => true,
            'reschedule_notes' => 'Client asked to reschedule for tomorrow',
        ]);

        $result = $this->runUpdate($order);

        $this->assertSame('success', $result['status']);

        $order->refresh();
        $assistanceCase = $order->metadata['assistance_case'];

        $this->assertSame(1, $assistanceCase['reschedule_count']);
        $this->assertNotNull($assistanceCase['incidents'][0]['rescheduled_at']);
        $this->assertArrayNotHasKey('pending_reschedule_decision', $assistanceCase);
        $this->assertSame(
            MovipassOrderStatusEnum::REQUEST_SUBMITTED->slug(),
            $order->orderStatus?->slug,
        );
    }

    public function testTheRescheduleLoopIsCappedSoACaseCannotBounceForever(): void
    {
        $order = $this->walkTo(
            $this->createRoadsideOrder(),
            MovipassOrderStatusEnum::AWAITING_OPERATOR,
            MovipassOrderStatusEnum::PROVIDER_ASSIGNED,
            MovipassOrderStatusEnum::DISPATCHED,
            MovipassOrderStatusEnum::ON_SITE,
            MovipassOrderStatusEnum::SERVICE_IN_PROGRESS,
        );

        $this->setAssistanceCase($order, [
            'pending_reschedule_decision' => true,
            'reschedule_count' => 3,
            'incidents' => [['reason' => 'Third failed attempt', 'rescheduled_at' => null]],
            'reschedule' => true,
        ]);

        $result = $this->runUpdate($order);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('already been rescheduled 3 times', $result['message']);
    }

    public function testClosingWithAnIncidentEndsTheCaseUnresolved(): void
    {
        $order = $this->walkTo(
            $this->createRoadsideOrder(),
            MovipassOrderStatusEnum::AWAITING_OPERATOR,
            MovipassOrderStatusEnum::PROVIDER_ASSIGNED,
            MovipassOrderStatusEnum::DISPATCHED,
            MovipassOrderStatusEnum::ON_SITE,
            MovipassOrderStatusEnum::SERVICE_IN_PROGRESS,
        );

        $this->setAssistanceCase($order, [
            'pending_reschedule_decision' => true,
            'incidents' => [['reason' => 'Vehicle is inaccessible', 'rescheduled_at' => null]],
            'close_with_incident' => true,
            'close_notes' => 'Cannot be rescheduled',
        ]);

        $result = $this->runUpdate($order);

        $this->assertSame('success', $result['status']);

        $order->refresh();
        $assistanceCase = $order->metadata['assistance_case'];

        $this->assertTrue($assistanceCase['closed_with_incident']);
        $this->assertFalse($assistanceCase['resolved']);
        $this->assertNull($assistanceCase['pin_hash']);
        $this->assertSame(
            MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->slug(),
            $order->orderStatus?->slug,
        );
    }

    public function testClosingWithAnIncidentRequiresAnIncidentToExist(): void
    {
        $order = $this->createRoadsideOrder(['close_with_incident' => true]);

        $result = $this->runUpdate($order);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('no incident has been registered', $result['message']);
    }

    private function runUpdate(Order $order): array
    {
        $this->setIntegration(
            $this->apps,
            IntegrationsEnum::MOVIPASS,
            MovipassHandler::class,
            $order->company,
            $this->authUser
        );
        Notification::fake();
        // The reschedule branch falls back to RetryNotifyMechanicsJob when no mechanic is free,
        // which is exactly the case in a test fixture — keep it off the wire.
        Queue::fake();

        return new SyncMovipassRoadsideAssistanceActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        )->execute($order, $this->apps, [
            'currentEventTypeName' => WorkflowEnum::UPDATED->value,
        ]);
    }

    private function createRoadsideOrder(array $assistanceCase = []): Order
    {
        $orderType = OrderTypes::firstOrCreate([
            'name' => OrderTypeEnum::ROADSIDE_ASSISTANCE->value,
            'apps_id' => $this->apps->getId(),
        ]);

        $order = Order::factory()
            ->withCompanyId($this->authUser->getCurrentCompany()->getId())
            ->withUserId($this->authUser->getId())
            ->create([
                'order_types_id' => $orderType->getId(),
                'user_email' => 'customer@movipass.test',
                'user_phone' => '77003300',
                'metadata' => ['assistance_case' => [
                    'service' => 'Light tow',
                    'service_type' => 'light_tow',
                    'location' => ['lat' => 13.6929, 'lng' => -89.2182],
                    'pin_hash' => 'pin-hash-placeholder',
                    ...$assistanceCase,
                ]],
            ]);

        // TransitionOrderStateAction refuses to move an order that has no current status, so the
        // case has to start on the real entry status rather than on null.
        $order->order_status_id = $this->statusId(MovipassOrderStatusEnum::REQUEST_SUBMITTED);
        $order->saveQuietly();

        return $order->refresh();
    }

    /**
     * Only declared transitions are honoured, so reaching a later stage means walking the graph
     * rather than jumping straight to it.
     */
    private function walkTo(Order $order, MovipassOrderStatusEnum ...$path): Order
    {
        foreach ($path as $status) {
            $order->transitionToStatus($this->authUser, $status->slug());
            $order->refresh();

            $this->assertSame(
                $status->slug(),
                $order->orderStatus?->slug,
                'Could not transition the order to ' . $status->value,
            );
        }

        return $order;
    }

    private function statusId(MovipassOrderStatusEnum $status): int
    {
        return (int) OrderStatus::where('apps_id', $this->apps->getId())
            ->where('slug', $status->slug())
            ->whereHas(
                'orderType',
                fn ($query) => $query->where('name', OrderTypeEnum::ROADSIDE_ASSISTANCE->value)
            )
            ->firstOrFail()
            ->getId();
    }

    private function setAssistanceCase(Order $order, array $assistanceCase): void
    {
        $metadata = $order->metadata ?? [];
        $order->metadata = [
            ...$metadata,
            'assistance_case' => [
                ...($metadata['assistance_case'] ?? []),
                ...$assistanceCase,
            ],
        ];
        $order->saveQuietly();
    }

    /**
     * transitionToStatus is a silent no-op when the status row is missing, so the flow assertions
     * would pass vacuously without the order type's statuses actually existing.
     */
    private function createRoadsideStatuses(): void
    {
        $cancelled = MovipassOrderStatusEnum::SERVICE_CANCELLED->value;
        $requestSubmitted = MovipassOrderStatusEnum::REQUEST_SUBMITTED->value;
        $notAuthorized = MovipassOrderStatusEnum::SERVICE_NOT_AUTHORIZED->value;

        new CreateOrderStatusesAction($this->apps, OrderTypeEnum::ROADSIDE_ASSISTANCE->value, [
            $requestSubmitted => [
                'is_default' => true,
                'transitions' => [
                    MovipassOrderStatusEnum::AWAITING_OPERATOR->value,
                    $cancelled,
                    $notAuthorized,
                ],
            ],
            MovipassOrderStatusEnum::AWAITING_OPERATOR->value => [
                'transitions' => [MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value, $requestSubmitted, $cancelled, $notAuthorized],
            ],
            MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value => [
                'transitions' => [MovipassOrderStatusEnum::DISPATCHED->value, $requestSubmitted, $cancelled],
            ],
            MovipassOrderStatusEnum::DISPATCHED->value => [
                'transitions' => [MovipassOrderStatusEnum::ON_SITE->value, $requestSubmitted, $cancelled],
            ],
            MovipassOrderStatusEnum::ON_SITE->value => [
                'transitions' => [MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->value, $requestSubmitted, $cancelled],
            ],
            MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->value => [
                'transitions' => [
                    MovipassOrderStatusEnum::SERVICE_COMPLETED->value,
                    MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->value,
                    $requestSubmitted,
                    $cancelled,
                ],
            ],
            MovipassOrderStatusEnum::SERVICE_COMPLETED->value => ['is_final' => true],
            MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->value => ['is_final' => true],
            $cancelled => ['is_final' => true],
            $notAuthorized => ['is_final' => true],
        ])->execute();
    }
}
