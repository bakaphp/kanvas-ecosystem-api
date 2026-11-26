<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Support\Setup;
use Kanvas\Regions\Models\Regions;
use Tests\Event\Concerns\BuildsInviteOnlyParking;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class TimeSlotCapacityTest extends TestCase
{
    use BuildsInviteOnlyParking;
    use DatabaseTransactions;
    use InventoryCases;

    protected $apps;
    protected $user;
    protected $company;
    protected $region;
    protected array $days;

    protected function connectionsToTransact(): array
    {
        return [null, 'event'];
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

        $this->days = $this->buildInviteOnlyParking(days: 2, capacity: 5);
        $this->flushAttempts();
    }

    protected function tearDown(): void
    {
        $this->flushAttempts();

        parent::tearDown();
    }

    public function testChangingOneDayLeavesTheOthersUntouched(): void
    {
        $response = $this->updateCapacity($this->days[0]['slot']->id, 8);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        $this->assertSame(8, $response->json('data.updateTimeSlotCapacity.initial_capacity'));
        $this->assertSame(8, $response->json('data.updateTimeSlotCapacity.available_slots'));
        $this->assertSame(5, $this->days[1]['slot']->refresh()->initial_capacity);
    }

    public function testCapacityBelowTheCurrentBookingsIsRefused(): void
    {
        $this->book(0);
        $this->book(0);

        $response = $this->updateCapacity($this->days[0]['slot']->id, 1);

        $this->assertStringContainsString('below the current bookings', (string) $response->json('errors.0.message'));
        $this->assertSame(5, $this->days[0]['slot']->refresh()->initial_capacity);
    }

    public function testCapacityEqualToTheBookingsIsAcceptedAndClosesTheSlot(): void
    {
        $this->book(0);
        $this->book(0);

        $response = $this->updateCapacity($this->days[0]['slot']->id, 2);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        $this->assertSame(0, $response->json('data.updateTimeSlotCapacity.available_slots'));
        $this->assertSame('booked', $this->days[0]['slot']->refresh()->status);
    }

    public function testASlotOfAnotherCompanyIsNotFound(): void
    {
        $this->days[0]['slot']->update(['companies_id' => $this->company->getId() + 100000]);

        $response = $this->updateCapacity($this->days[0]['slot']->id, 9);

        $this->assertNotNull($response->json('errors'));
        $this->assertSame(5, $this->days[0]['slot']->refresh()->initial_capacity);
    }

    private function book(int $day): void
    {
        $code = $this->issueInvite($this->days[$day]['version']);
        $response = $this->bookWithInvite($this->days[$day]['slot'], $code);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        $this->flushAttempts();
    }

    private function flushAttempts(): void
    {
        RateLimiter::clear('invite-code:user:' . $this->user->getId());
        RateLimiter::clear('invite-code:ip:127.0.0.1');
    }

    private function updateCapacity(int $slotId, int $capacity)
    {
        return $this->graphQL('
            mutation update($id: ID!, $capacity: Int!) {
                updateTimeSlotCapacity(id: $id, capacity: $capacity) {
                    id
                    initial_capacity
                    booked_slots
                    available_slots
                }
            }
        ', ['id' => (string) $slotId, 'capacity' => $capacity], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);
    }
}
