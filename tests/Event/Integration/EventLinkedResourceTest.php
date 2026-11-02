<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event as EventData;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Support\Setup;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class EventLinkedResourceTest extends TestCase
{
    private function createLead(): Lead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new Setup($app, $user, $company)->run();

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        return Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();
    }

    private function createEvent(array $overrides, ?Carbon $start = null): Event
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $start ??= Carbon::now('UTC')->addDays(2)->startOfHour();
        $end = $start->copy()->addHour();

        $input = array_merge([
            'name' => 'Linked resource test',
            'slug' => 'linked-resource-' . Str::uuid(),
            'dates' => [
                [
                    'date' => $start->toDateString(),
                    'start_time' => $start->format('H:i'),
                    'end_time' => $end->format('H:i'),
                ],
            ],
            'start_at' => $start->toIso8601String(),
            'end_at' => $end->toIso8601String(),
        ], $overrides);

        return new CreateEventAction(EventData::from($app, $user, $user->getCurrentCompany(), $input))
            ->disableWorkflow()
            ->withoutNotifications()
            ->execute();
    }

    public function testLinkedResourceReadsThePrimaryResource(): void
    {
        $lead = $this->createLead();

        $event = $this->createEvent(['resource' => $lead]);

        $this->assertTrue($lead->is($event->linkedResource(Lead::class)));
        $this->assertNull($event->linkedResource(People::class));
    }

    public function testLinkedResourceFallsBackToTheEventResourcesRow(): void
    {
        $lead = $this->createLead();

        $event = $this->createEvent([
            'resources' => [
                [
                    'resources_id' => $lead->getId(),
                    'resources_type' => 'lead',
                ],
            ],
        ]);

        $this->assertNull($event->resources_id);
        $this->assertTrue($lead->is($event->linkedResource(Lead::class)));
        $this->assertTrue($lead->is($event->resources()->first()->resource));
        $this->assertTrue($lead->eventResources()->where('event_id', $event->getId())->exists());
    }

    public function testStartsAtPrefersTheVersionStartAndFallsBackToTheDateRow(): void
    {
        $start = Carbon::now('UTC')->addDays(3)->startOfHour();
        $event = $this->createEvent([], $start);

        $this->assertTrue($event->startsAt()->equalTo($start));

        $version = $event->latestVersion();
        $version->start_at = null;
        $version->saveOrFail();

        $fromDateRow = $event->fresh()->startsAt();
        $this->assertNotNull($fromDateRow);
        $this->assertSame($start->toDateString(), $fromDateRow->toDateString());
        $this->assertSame($start->format('H:i'), $fromDateRow->format('H:i'));

        $version->dates()->delete();
        $this->assertNull($event->fresh()->startsAt());
    }
}
