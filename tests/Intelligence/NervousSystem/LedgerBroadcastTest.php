<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Facades\Event;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Enums\LedgerConfigurationEnum;
use Kanvas\NervousSystem\Ledger\Events\LedgerEventBroadcast;
use Tests\TestCase;

class LedgerBroadcastTest extends TestCase
{
    public function testNoBroadcastWhenAppFlagIsOff(): void
    {
        Event::fake([LedgerEventBroadcast::class]);

        $app = app(Apps::class);
        $app->set(LedgerConfigurationEnum::BROADCAST_LEDGER_EVENTS->value, false);

        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'TestDomain',
                eventType: 'broadcast.test.off',
                status: EventStatusEnum::INFO,
            ),
        )->execute();

        Event::assertNotDispatched(LedgerEventBroadcast::class);
    }

    public function testBroadcastsWhenAppFlagIsOn(): void
    {
        Event::fake([LedgerEventBroadcast::class]);

        $app = app(Apps::class);
        $app->set(LedgerConfigurationEnum::BROADCAST_LEDGER_EVENTS->value, true);

        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $event = new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'TestDomain',
                eventType: 'broadcast.test.on',
                status: EventStatusEnum::INFO,
            ),
        )->execute();

        Event::assertDispatched(
            LedgerEventBroadcast::class,
            fn (LedgerEventBroadcast $broadcast) => $broadcast->event->id === $event->id
        );

        $app->set(LedgerConfigurationEnum::BROADCAST_LEDGER_EVENTS->value, false);
    }

    public function testAllowlistFiltersByPrefix(): void
    {
        Event::fake([LedgerEventBroadcast::class]);

        $app = app(Apps::class);
        $app->set(LedgerConfigurationEnum::BROADCAST_LEDGER_EVENTS->value, true);
        $app->set(LedgerConfigurationEnum::BROADCAST_LEDGER_EVENT_TYPES->value, ['plan.', 'schedule.fired']);

        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $matched = new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'TestDomain',
                eventType: 'plan.task.completed',
                status: EventStatusEnum::INFO,
            ),
        )->execute();

        $skipped = new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'TestDomain',
                eventType: 'lead.created',
                status: EventStatusEnum::INFO,
            ),
        )->execute();

        Event::assertDispatched(
            LedgerEventBroadcast::class,
            fn (LedgerEventBroadcast $broadcast) => $broadcast->event->id === $matched->id
        );

        Event::assertNotDispatched(
            LedgerEventBroadcast::class,
            fn (LedgerEventBroadcast $broadcast) => $broadcast->event->id === $skipped->id
        );

        $app->set(LedgerConfigurationEnum::BROADCAST_LEDGER_EVENTS->value, false);
        $app->set(LedgerConfigurationEnum::BROADCAST_LEDGER_EVENT_TYPES->value, []);
    }

    public function testNoAllowlistBroadcastsEverything(): void
    {
        Event::fake([LedgerEventBroadcast::class]);

        $app = app(Apps::class);
        $app->set(LedgerConfigurationEnum::BROADCAST_LEDGER_EVENTS->value, true);
        // Explicitly clear the allowlist key so the lookup returns null/missing.
        $app->set(LedgerConfigurationEnum::BROADCAST_LEDGER_EVENT_TYPES->value, null);

        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $event = new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'TestDomain',
                eventType: 'something.totally.unrelated',
                status: EventStatusEnum::INFO,
            ),
        )->execute();

        Event::assertDispatched(
            LedgerEventBroadcast::class,
            fn (LedgerEventBroadcast $broadcast) => $broadcast->event->id === $event->id
        );

        $app->set(LedgerConfigurationEnum::BROADCAST_LEDGER_EVENTS->value, false);
    }

    public function testBroadcastChannelsMatchTenantConvention(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $event = new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'TestDomain',
                eventType: 'broadcast.test.channels',
                status: EventStatusEnum::INFO,
            ),
        )->execute();

        $broadcast = new LedgerEventBroadcast($event);
        $channels = $broadcast->broadcastOn();

        $names = array_map(fn ($c) => $c->name, $channels);

        $this->assertContains(
            'company-' . $company->getId() . '-app-' . $app->getId() . '-ledger',
            $names,
        );
        $this->assertContains('app-' . $app->getId() . '-ledger', $names);
        $this->assertSame('ledger.event.appended', $broadcast->broadcastAs());
    }
}
