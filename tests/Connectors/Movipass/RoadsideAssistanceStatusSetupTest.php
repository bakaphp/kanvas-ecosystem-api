<?php

declare(strict_types=1);

namespace Tests\Connectors\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\SyncRoadsideAssistanceStatusesAction;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Support\RoadsideAssistanceStatusMap;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Kanvas\Souk\Orders\Models\OrderTypes;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Serial: the roadside statuses are app-wide rows every roadside test syncs, and this one soft-deletes
 * them and asserts exact counts.
 */
#[Group('serial')]
final class RoadsideAssistanceStatusSetupTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'commerce'];

    protected Apps $apps;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->sync();
    }

    public function testASecondRunChangesNothing(): void
    {
        $summary = $this->sync();

        unset($summary['duplicate_statuses'], $summary['unlisted_transitions']);
        $this->assertSame(array_fill_keys(array_keys($summary), 0), $summary);
        $this->assertSame($this->expectedTransitionCount(), $this->activeTransitions()->count());
    }

    public function testADeletedStatusAndTransitionAreRestoredNotDuplicated(): void
    {
        $status = $this->orderStatus(MovipassOrderStatusEnum::ON_SITE);
        $transition = $this->transition(MovipassOrderStatusEnum::DISPATCHED, MovipassOrderStatusEnum::ON_SITE);
        $status->delete();
        $transition->delete();

        $summary = $this->sync();

        $this->assertSame(1, $summary['statuses_restored']);
        $this->assertSame(1, $summary['transitions_restored']);
        $this->assertSame(0, $summary['statuses_created']);
        $this->assertSame(0, $summary['transitions_created']);
        $this->assertSame(1, OrderStatus::withTrashed()
            ->where('order_types_id', $this->orderType()->getId())
            ->where('slug', MovipassOrderStatusEnum::ON_SITE->slug())
            ->count());
        $this->assertSame($transition->getId(), $this->transition(MovipassOrderStatusEnum::DISPATCHED, MovipassOrderStatusEnum::ON_SITE)->getId());
    }

    public function testATransitionNotInTheMapIsKeptAndReported(): void
    {
        $this->createUnlistedTransition();

        $summary = $this->sync();

        $this->assertSame(0, $summary['transitions_removed']);
        $this->assertContains('unlisted', $summary['unlisted_transitions']);
        $this->assertSame($this->expectedTransitionCount() + 1, $this->activeTransitions()->count());
    }

    public function testPruneRemovesATransitionNotInTheMap(): void
    {
        $this->createUnlistedTransition();

        $summary = $this->sync(prune: true);

        $this->assertSame(1, $summary['transitions_removed']);
        $this->assertNotContains('unlisted', $summary['unlisted_transitions']);
        $this->assertSame($this->expectedTransitionCount(), $this->activeTransitions()->count());
    }

    public function testAnExistingStatusKeepsItsName(): void
    {
        $this->orderStatus(MovipassOrderStatusEnum::REQUEST_SUBMITTED)->updateQuietly(['name' => 'Request_submitted']);

        $summary = $this->sync();

        $this->assertSame(0, $summary['statuses_updated']);
        $this->assertSame('Request_submitted', $this->orderStatus(MovipassOrderStatusEnum::REQUEST_SUBMITTED)->name);
    }

    public function testAChangedFlagIsPutBack(): void
    {
        $this->orderStatus(MovipassOrderStatusEnum::SERVICE_COMPLETED)->updateQuietly(['is_final' => 0]);
        $this->orderStatus(MovipassOrderStatusEnum::DISPATCHED)->updateQuietly(['is_default' => 1]);

        $summary = $this->sync();

        $this->assertSame(2, $summary['statuses_updated']);
        $this->assertSame(1, (int) $this->orderStatus(MovipassOrderStatusEnum::SERVICE_COMPLETED)->is_final);
        $this->assertSame(0, (int) $this->orderStatus(MovipassOrderStatusEnum::DISPATCHED)->is_default);
    }

    /**
     * Regression: closing with an incident from a stage before the service starts was a silent no-op.
     */
    public function testEveryOpenStageCanCloseAsNotResolved(): void
    {
        foreach ([
            MovipassOrderStatusEnum::REQUEST_SUBMITTED,
            MovipassOrderStatusEnum::AWAITING_OPERATOR,
            MovipassOrderStatusEnum::PROVIDER_ASSIGNED,
            MovipassOrderStatusEnum::DISPATCHED,
            MovipassOrderStatusEnum::ON_SITE,
            MovipassOrderStatusEnum::SERVICE_IN_PROGRESS,
        ] as $from) {
            $this->assertNotNull(
                $this->transition($from, MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED),
                $from->value . ' cannot close as not resolved',
            );
        }
    }

    private function sync(bool $prune = false): array
    {
        return new SyncRoadsideAssistanceStatusesAction($this->apps, $prune)->execute();
    }

    private function createUnlistedTransition(): void
    {
        OrderStatusTransitions::create([
            'order_types_id' => $this->orderType()->getId(),
            'from_status_id' => $this->orderStatus(MovipassOrderStatusEnum::SERVICE_COMPLETED)->getId(),
            'to_status_id' => $this->orderStatus(MovipassOrderStatusEnum::REQUEST_SUBMITTED)->getId(),
            'name' => 'unlisted',
            'companies_id' => 0,
        ]);
    }

    private function orderType(): OrderTypes
    {
        return OrderTypes::where('apps_id', $this->apps->getId())
            ->where('name', OrderTypeEnum::ROADSIDE_ASSISTANCE->value)
            ->orderBy('id')
            ->firstOrFail();
    }

    private function orderStatus(MovipassOrderStatusEnum $status): OrderStatus
    {
        return OrderStatus::where('order_types_id', $this->orderType()->getId())
            ->where('slug', $status->slug())
            ->orderBy('id')
            ->firstOrFail();
    }

    private function transition(MovipassOrderStatusEnum $from, MovipassOrderStatusEnum $to): ?OrderStatusTransitions
    {
        return OrderStatusTransitions::where('order_types_id', $this->orderType()->getId())
            ->where('from_status_id', $this->orderStatus($from)->getId())
            ->where('to_status_id', $this->orderStatus($to)->getId())
            ->first();
    }

    private function activeTransitions()
    {
        return OrderStatusTransitions::where('order_types_id', $this->orderType()->getId());
    }

    private function expectedTransitionCount(): int
    {
        return array_sum(array_map(
            fn (array $config) => count($config['transitions'] ?? []),
            RoadsideAssistanceStatusMap::definition(),
        ));
    }
}
