<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Actions\CancelEventAction;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Validators\EventTimeSlotValidator;
use Kanvas\Event\Passes\Actions\ScanEventPassAction;
use Kanvas\Event\Support\Setup;
use Kanvas\Regions\Models\Regions;
use Tests\Event\Concerns\BuildsInviteOnlyFestival;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class TimeSlotAdmissionStatsTest extends TestCase
{
    use BuildsInviteOnlyFestival;
    use DatabaseTransactions;
    use InventoryCases;

    protected $apps;
    protected $user;
    protected $company;
    protected $region;
    protected array $days;

    protected function connectionsToTransact(): array
    {
        return [null, 'event', 'commerce'];
    }

    public function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();
        $this->region = Regions::getDefault($this->company, $this->apps);
        $this->company->timezone = 'UTC';
        $this->company->saveOrFail();

        new Setup($this->apps, $this->user, $this->company)->run();

        $this->days = $this->buildFestival(days: 2, capacity: 10);
        RateLimiter::clear('invite-code:user:' . $this->user->getId());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        RateLimiter::clear('invite-code:user:' . $this->user->getId());

        parent::tearDown();
    }

    public function testCancelledBookingsAreNotCountedAsBooked(): void
    {
        $this->bookDay(0);
        $cancelled = $this->bookDay(0);
        $this->cancel($cancelled);

        $stats = $this->stats();

        $this->assertSame(20, $stats['capacity']);
        $this->assertSame(1, $stats['booked']);
    }

    public function testAdmittedCountsOnlyBookingsThatPassedTheGate(): void
    {
        $admitted = $this->bookDay(0);
        $this->bookDay(0);
        $this->scan($admitted, 0);

        $stats = $this->stats();

        $this->assertSame(2, $stats['booked']);
        $this->assertSame(1, $stats['admitted']);
    }

    public function testRejectedScansAreNotAdmissions(): void
    {
        $booking = $this->bookDay(1);
        $this->scan($booking, 0);

        $this->assertSame(0, $this->stats()['admitted']);
    }

    public function testArrivalsAreGroupedByTheChosenHourAndSkipCancelledBookings(): void
    {
        $this->bookDay(0, ['arrival_hour' => '18:00']);
        $this->bookDay(0, ['arrival_hour' => '18:30']);
        $this->bookDay(1, ['arrival_hour' => '21:00']);
        $cancelled = $this->bookDay(0, ['arrival_hour' => '21:00']);
        $this->cancel($cancelled);
        $this->bookDay(0);

        $arrivals = collect($this->stats()['byArrivalHour'])->pluck('arrivals', 'label')->all();

        $this->assertSame(['18:00' => 2, '21:00' => 1], $arrivals);
    }

    public function testCapacityCheckOnUpdateIgnoresCancelledBookings(): void
    {
        $this->days[0]['slot']->update(['initial_capacity' => 1]);
        $cancelled = $this->bookDay(0);
        $this->cancel($cancelled);
        $live = $this->bookDay(0);
        $slot = $this->days[0]['slot']->refresh();
        $this->assertTrue($slot->isFullyBooked());

        EventTimeSlotValidator::validateForUpdate(
            $slot->resources_id,
            $slot->resources_type,
            $this->company->getId(),
            $this->apps->getId(),
            $slot->start_at->toDateString(),
            $slot->start_at->format('H:i'),
            $slot->end_at->format('H:i'),
            $live->event_id,
            $slot->id
        );

        $this->assertTrue(true);
    }

    private function bookDay(int $day, array $metadata = []): EventVersion
    {
        $code = $this->issueInvite($this->days[$day]['version']);
        $response = $this->bookWithInvite($this->days[$day]['slot'], $code, $metadata);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        RateLimiter::clear('invite-code:user:' . $this->user->getId());

        return EventVersion::findOrFail($response->json('data.bookTimeSlot.id'));
    }

    private function cancel(EventVersion $booking): void
    {
        new CancelEventAction($booking)->withoutNotifications()->disableWorkflow()->execute();
    }

    private function scan(EventVersion $booking, int $day): void
    {
        Carbon::setTestNow($this->days[$day]['slot']->start_at->copy()->addHours(2));

        new ScanEventPassAction(
            $this->apps,
            $this->user,
            $this->company,
            $this->gatedProduct
        )->execute((string) $booking->getPassCode());

        Carbon::setTestNow();
    }

    private function stats(): array
    {
        $response = $this->graphQL('
            query stats($input: TimeSlotStatsInput!) {
                timeSlotStats(input: $input) {
                    capacity
                    booked
                    admitted
                    byArrivalHour { label arrivals }
                }
            }
        ', [
            'input' => [
                'startDate' => Carbon::now()->format('Y-m-d'),
                'endDate' => Carbon::now()->addDays(20)->format('Y-m-d'),
                'timezone' => 'UTC',
                'resources_id' => (string) $this->gatedVariant->getId(),
                'resources_type' => $this->gatedVariant->getMorphClass(),
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));

        return $response->json('data.timeSlotStats');
    }
}
