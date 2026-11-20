<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Movipass;

use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideCaseStateEnum as State;
use Tests\TestCase;

final class RoadsideCaseStateEnumTest extends TestCase
{
    public function testTerminalStatesGoNowhere(): void
    {
        foreach ([State::COMPLETED, State::NOT_RESOLVED, State::CANCELLED] as $state) {
            $this->assertTrue($state->isTerminal());
            $this->assertSame([], $state->next());
        }
    }

    public function testTheServiceCannotSkipFromAssignmentStraightToInProgress(): void
    {
        $this->assertFalse(State::TECHNICIAN_ASSIGNED->canMoveTo(State::IN_PROGRESS));
        $this->assertFalse(State::EN_ROUTE->canMoveTo(State::IN_PROGRESS));
        $this->assertTrue(State::ON_SITE->canMoveTo(State::IN_PROGRESS));
    }

    public function testOnlyInProgressCanComplete(): void
    {
        foreach (State::cases() as $state) {
            $this->assertSame(
                $state === State::IN_PROGRESS,
                $state->canMoveTo(State::COMPLETED),
                $state->value . ' should ' . ($state === State::IN_PROGRESS ? '' : 'not ') . 'complete',
            );
        }
    }

    public function testEveryStateHasTheSpecUserText(): void
    {
        $this->assertSame('Tu técnico está cerca', State::NEARBY->userLabel());
        $this->assertSame('Estamos asignando otro técnico', State::REASSIGNMENT->userLabel());

        foreach (State::cases() as $state) {
            $this->assertNotSame('', $state->userLabel());
        }
    }

    public function testOrderStatusesMapToTheirHomologatedState(): void
    {
        $this->assertSame(State::PENDING_ASSIGNMENT, State::forOrderStatus(MovipassOrderStatusEnum::AWAITING_OPERATOR->slug(), State::CREATED));
        $this->assertSame(State::TECHNICIAN_ASSIGNED, State::forOrderStatus(MovipassOrderStatusEnum::PROVIDER_ASSIGNED->slug(), State::PENDING_ASSIGNMENT));
        $this->assertNull(State::forOrderStatus(MovipassOrderStatusEnum::DISPATCHED->slug(), State::TECHNICIAN_ASSIGNED));
        $this->assertSame(State::CANCELLED, State::forOrderStatus(MovipassOrderStatusEnum::SERVICE_NOT_AUTHORIZED->slug(), State::CABIN_REVIEW));
    }

    /**
     * Spec §12: a technician dropping the case never cancels the user's order.
     */
    public function testBackToRequestSubmittedIsAReassignmentOnlyOnceATechnicianWasOnIt(): void
    {
        $slug = MovipassOrderStatusEnum::REQUEST_SUBMITTED->slug();

        $this->assertSame(State::REASSIGNMENT, State::forOrderStatus($slug, State::EN_ROUTE));
        $this->assertSame(State::REASSIGNMENT, State::forOrderStatus($slug, State::INCIDENT));
        $this->assertNull(State::forOrderStatus($slug, State::PENDING_ASSIGNMENT));
        $this->assertNull(State::forOrderStatus($slug, null));
    }
}
