<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\NervousSystem\Ledger\Enums\EventCategoryEnum;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Tests\TestCase;

class LedgerEventCategoryTest extends TestCase
{
    public function testErrorStatusRoutesToWarningRegardlessOfEventType(): void
    {
        $this->assertSame(
            EventCategoryEnum::WARNING,
            Event::categoryFor('plan.created', EventStatusEnum::ERROR->value),
        );
        $this->assertSame(
            EventCategoryEnum::WARNING,
            Event::categoryFor('integration.salesforce.completed', EventStatusEnum::ERROR->value),
        );
    }

    public function testFailureSuffixedEventsRouteToWarning(): void
    {
        $this->assertSame(
            EventCategoryEnum::WARNING,
            Event::categoryFor('plan.agent.invocation_failed', EventStatusEnum::INFO->value),
        );
        $this->assertSame(
            EventCategoryEnum::WARNING,
            Event::categoryFor('integration.shopify.failed', EventStatusEnum::INFO->value),
        );
    }

    public function testPlanLifecycleEventsRouteToDecide(): void
    {
        $this->assertSame(
            EventCategoryEnum::DECIDE,
            Event::categoryFor('plan.created', EventStatusEnum::INFO->value),
        );
        $this->assertSame(
            EventCategoryEnum::DECIDE,
            Event::categoryFor('plan.approved', EventStatusEnum::INFO->value),
        );
        $this->assertSame(
            EventCategoryEnum::DECIDE,
            Event::categoryFor('skill.granted', EventStatusEnum::INFO->value),
        );
    }

    public function testExecutionEventsRouteToAct(): void
    {
        $this->assertSame(
            EventCategoryEnum::ACT,
            Event::categoryFor('plan.task.completed', EventStatusEnum::INFO->value),
        );
        $this->assertSame(
            EventCategoryEnum::ACT,
            Event::categoryFor('integration.salesforce.completed', EventStatusEnum::INFO->value),
        );
        $this->assertSame(
            EventCategoryEnum::ACT,
            Event::categoryFor('plan.agent.replied', EventStatusEnum::INFO->value),
        );
    }

    public function testContextEventsRouteToUnderstand(): void
    {
        $this->assertSame(
            EventCategoryEnum::UNDERSTAND,
            Event::categoryFor('context.assembled', EventStatusEnum::INFO->value),
        );
        $this->assertSame(
            EventCategoryEnum::UNDERSTAND,
            Event::categoryFor('lead.scored', EventStatusEnum::INFO->value),
        );
    }

    public function testIncomingEventsRouteToSignal(): void
    {
        $this->assertSame(
            EventCategoryEnum::SIGNAL,
            Event::categoryFor('signal.lead_detected', EventStatusEnum::INFO->value),
        );
        $this->assertSame(
            EventCategoryEnum::SIGNAL,
            Event::categoryFor('lead.created', EventStatusEnum::INFO->value),
        );
        $this->assertSame(
            EventCategoryEnum::SIGNAL,
            Event::categoryFor('webhook.received', EventStatusEnum::INFO->value),
        );
    }

    public function testInternalEventsReturnNull(): void
    {
        $this->assertNull(
            Event::categoryFor('dashboard.metrics_rolled_up', EventStatusEnum::INFO->value),
        );
        $this->assertNull(
            Event::categoryFor('plan.agent.wake_dispatched', EventStatusEnum::INFO->value),
        );
    }

    public function testUnknownEventTypesReturnNull(): void
    {
        $this->assertNull(
            Event::categoryFor('something.totally.new', EventStatusEnum::INFO->value),
        );
    }

    public function testAccessorReadsCategoryFromEventTypeAndStatus(): void
    {
        $event = new Event();
        $event->event_type = 'plan.approved';
        $event->status = EventStatusEnum::INFO->value;

        $this->assertSame('DECIDE', $event->category);
    }
}
