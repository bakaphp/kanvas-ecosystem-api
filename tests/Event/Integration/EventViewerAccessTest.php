<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\MovipassRolesEnum;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Reports\Services\EventReportAccess;
use Kanvas\Event\Support\Setup;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\Event\Concerns\BuildsInviteOnlyParking;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class EventViewerAccessTest extends TestCase
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

    public function testStatsCountCodesUsesAndBookingsPerDay(): void
    {
        $this->issueEventCode(uses: 2);
        $this->issueEventCode(uses: 1);
        $code = $this->issueEventCode(uses: 2);
        $this->bookWithInvite($this->days[0]['slot'], $code);
        $this->flushAttempts();
        $this->bookWithInvite($this->days[1]['slot'], $code);

        $response = $this->inviteStats();

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        $this->assertSame([
            'codes_issued' => 3,
            'uses_issued' => 5,
            'uses_redeemed' => 2,
            'days' => [
                ['date' => $this->days[0]['version']->start_at->toDateString(), 'bookings' => 1],
                ['date' => $this->days[1]['version']->start_at->toDateString(), 'bookings' => 1],
            ],
        ], $response->json('data.eventInviteStats'));
    }

    public function testRevokedCodesAreNotCounted(): void
    {
        $this->issueEventCode(uses: 1);
        $this->issueEventCode(uses: 1);
        ParticipantPass::where('event_id', $this->gatedEvent->getId())
            ->orderBy('id')
            ->first()
            ->update(['revoked_at' => now()]);

        $this->assertSame(1, $this->inviteStats()->json('data.eventInviteStats.codes_issued'));
    }

    public function testCodesOfAnotherCompanyAreNeverCounted(): void
    {
        $this->issueEventCode(uses: 1);
        $this->issueEventCode(uses: 1);
        $otherCompany = Companies::factory()->create();
        ParticipantPass::where('event_id', $this->gatedEvent->getId())
            ->orderBy('id')
            ->first()
            ->update(['companies_id' => $otherCompany->getId()]);

        $this->assertSame(1, $this->inviteStats()->json('data.eventInviteStats.codes_issued'));
    }

    public function testAnEventOfAnotherCompanyIsNotFound(): void
    {
        $otherCompany = Companies::factory()->create();
        $this->gatedEvent->update(['companies_id' => $otherCompany->getId()]);

        $this->assertNotNull($this->inviteStats()->json('errors'));
    }

    public function testTheViewerRoleCanReadTheReportsAndNothingElse(): void
    {
        Artisan::call('kanvas:movipass-setup-roles', ['app_id' => $this->apps->getId()]);
        $viewer = $this->makeUserWithRole(MovipassRolesEnum::EVENT_VIEWER->value);
        $stranger = $this->makeUserWithRole(null);

        $this->actingAs($viewer, 'api');
        EventReportAccess::authorizeStats($viewer);
        EventReportAccess::authorizeBookings($viewer);
        $this->assertFalse($viewer->isAdmin());
        $this->assertTrue($viewer->can('view-event-reports'));
        $this->assertFalse($viewer->can('validate-pass'));
        $this->assertFalse($viewer->can('wallet-recharge'));
        $this->assertFalse($viewer->can('update-orders'));

        $this->actingAs($stranger, 'api');
        $this->expectException(AuthorizationException::class);
        EventReportAccess::authorizeStats($stranger);
    }

    public function testTheViewerCannotGenerateCodesOrChangeCapacity(): void
    {
        Queue::fake();
        Artisan::call('kanvas:movipass-setup-roles', ['app_id' => $this->apps->getId()]);
        $viewer = $this->makeUserWithRole(MovipassRolesEnum::EVENT_VIEWER->value);
        $this->actingAs($viewer, 'api');

        $generate = $this->graphQL(
            'mutation { generateEventInviteCodes(input: { event_id: ' . $this->gatedEvent->getId() . ', quantity: 5 }) { queued } }'
        );
        $capacity = $this->graphQL(
            'mutation { updateTimeSlotCapacity(id: ' . $this->days[0]['slot']->id . ', capacity: 9) { id } }'
        );

        $this->assertNotNull($generate->json('errors'));
        $this->assertNotNull($capacity->json('errors'));
        $this->assertSame(5, $this->days[0]['slot']->refresh()->initial_capacity);
    }

    private function makeUserWithRole(?string $role): Users
    {
        $user = Users::factory()->create(['email' => 'viewer-' . uniqid() . '@example.test']);
        Bouncer::scope()->to(RolesEnums::getScope($this->apps));

        if ($role !== null) {
            Bouncer::assign($role)->to($user);
        }

        Bouncer::refreshFor($user);

        return $user;
    }

    private function inviteStats(): TestResponse
    {
        return $this->graphQL('
            query stats($event_id: ID!) {
                eventInviteStats(event_id: $event_id) { codes_issued uses_issued uses_redeemed days { date bookings } }
            }
        ', ['event_id' => (string) $this->gatedEvent->getId()], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);
    }

    private function flushAttempts(): void
    {
        RateLimiter::clear('invite-code:user:' . $this->user->getId());
        RateLimiter::clear('invite-code:ip:127.0.0.1');
    }
}
