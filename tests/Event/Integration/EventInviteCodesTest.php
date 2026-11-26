<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Passes\Actions\RedeemPassAction;
use Kanvas\Event\Passes\Jobs\GenerateEventInviteCodesJob;
use Kanvas\Event\Support\Setup;
use Tests\Event\Concerns\CreatesEventVersions;
use Tests\TestCase;

class EventInviteCodesTest extends TestCase
{
    use CreatesEventVersions;
    use DatabaseTransactions;

    protected $apps;
    protected $user;
    protected $company;
    protected EventVersion $eventVersion;

    protected function connectionsToTransact(): array
    {
        return [null, 'event'];
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();

        new Setup($this->apps, $this->user, $this->company)->run();

        $this->eventVersion = $this->createEventVersion($this->createEvent());
    }

    public function testJobIssuesAnonymousEightCharacterCodes(): void
    {
        $this->runJob(5);

        $passes = $this->passes()->get();
        $this->assertCount(5, $passes);
        $this->assertCount(5, $passes->map->plainCode()->unique());

        foreach ($passes as $pass) {
            $this->assertNull($pass->participant_id);
            $this->assertSame('qr', $pass->format);
            $this->assertSame(8, strlen($pass->plainCode()));
        }
    }

    public function testPrefixedCodesKeepTheEightCharacterTail(): void
    {
        $this->runJob(3, 'SCOTIA');

        foreach ($this->passes()->get() as $pass) {
            $this->assertMatchesRegularExpression('/^SCOTIA-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{8}$/', $pass->plainCode());
        }
    }

    public function testAPrefixedCodeRedeemsEvenInLowerCase(): void
    {
        $this->runJob(1, 'SCOTIA');
        $pass = $this->passes()->firstOrFail();

        $redeemed = new RedeemPassAction(
            $this->apps,
            $this->company,
            strtolower($pass->plainCode()),
            $this->eventVersion,
            $this->createEventVersion($this->createEvent())
        )->execute();

        $this->assertNotNull($redeemed->redeemed_at);
    }

    public function testGraphQLNormalizesThePrefixAndRefusesAnInvalidOne(): void
    {
        Queue::fake();

        $ok = $this->generateMutation($this->eventVersion->event_id, 5, ' scotia ');
        $short = $this->generateMutation($this->eventVersion->event_id, 5, 'A');
        $symbols = $this->generateMutation($this->eventVersion->event_id, 5, 'SCO-TIA');

        $this->assertNull($ok->json('errors'), (string) $ok->getContent());
        Queue::assertPushed(GenerateEventInviteCodesJob::class, fn (GenerateEventInviteCodesJob $job) => $job->prefix === 'SCOTIA');
        $this->assertNotNull($short->json('errors'));
        $this->assertNotNull($symbols->json('errors'));
    }

    public function testAnonymousCodeRedeemsForABooking(): void
    {
        $this->runJob(1);
        $pass = $this->passes()->firstOrFail();

        $redeemed = new RedeemPassAction(
            $this->apps,
            $this->company,
            $pass->plainCode(),
            $this->eventVersion,
            $this->createEventVersion($this->createEvent())
        )->execute();

        $this->assertNotNull($redeemed->redeemed_at);
    }

    public function testStatusFollowsRedemptionAndRevocation(): void
    {
        $this->runJob(3);
        [$available, $redeemed, $revoked] = $this->passes()->orderBy('id')->get()->all();

        $this->redeem($redeemed);
        $revoked->update(['revoked_at' => Carbon::now()]);

        $this->assertSame('available', $available->refresh()->inviteStatus());
        $this->assertSame('redeemed', $redeemed->refresh()->inviteStatus());
        $this->assertSame('revoked', $revoked->refresh()->inviteStatus());
    }

    public function testCodeOfACancelledBookingIsAvailableAgain(): void
    {
        $this->runJob(1);
        $pass = $this->passes()->firstOrFail();
        $booking = $this->redeem($pass);

        $booking->update(['is_deleted' => 1]);

        $this->assertSame('available', $pass->refresh()->inviteStatus());
    }

    public function testGraphQLQueuesOneJobPerHundredCodes(): void
    {
        Queue::fake();

        $response = $this->generateMutation($this->eventVersion->event_id, 250);

        $this->assertNull($response->json('errors'), (string) $response->getContent());
        $this->assertSame(250, $response->json('data.generateEventInviteCodes.queued'));
        Queue::assertPushed(GenerateEventInviteCodesJob::class, 3);
        Queue::assertPushed(GenerateEventInviteCodesJob::class, fn (GenerateEventInviteCodesJob $job) => $job->quantity === 50);
    }

    public function testGraphQLRejectsQuantityOutOfRange(): void
    {
        Queue::fake();

        $this->assertNotNull($this->generateMutation($this->eventVersion->event_id, 0)->json('errors'));
        $this->assertNotNull($this->generateMutation($this->eventVersion->event_id, 1001)->json('errors'));
        Queue::assertNotPushed(GenerateEventInviteCodesJob::class);
    }

    public function testGraphQLRejectsAFinishedDay(): void
    {
        Queue::fake();
        $finished = $this->createEventVersion($this->createEvent(), startAt: Carbon::now()->subDays(3));

        $response = $this->generateMutation($finished->event_id, 10);

        $this->assertNotNull($response->json('errors'));
        Queue::assertNotPushed(GenerateEventInviteCodesJob::class);
    }

    public function testGraphQLRejectsADayOfAnotherCompany(): void
    {
        Queue::fake();
        $otherCompany = Companies::factory()->create();
        $foreign = $this->createEventVersion($this->createEvent($otherCompany), $otherCompany);

        $response = $this->generateMutation($foreign->event_id, 10);

        $this->assertNotNull($response->json('errors'));
        Queue::assertNotPushed(GenerateEventInviteCodesJob::class);
    }

    public function testGraphQLRejectsMoreUsesThanDaysOfTheEvent(): void
    {
        Queue::fake();

        $tooMany = $this->generateMutation($this->eventVersion->event_id, 5, uses: 2);
        $none = $this->generateMutation($this->eventVersion->event_id, 5, uses: 0);

        $this->assertNotNull($tooMany->json('errors'));
        $this->assertNotNull($none->json('errors'));
        Queue::assertNotPushed(GenerateEventInviteCodesJob::class);
    }

    public function testGraphQLPassesTheUsesToTheJob(): void
    {
        Queue::fake();
        $this->createEventVersion($this->eventVersion->event, startAt: Carbon::now()->addDays(2));

        $response = $this->generateMutation($this->eventVersion->event_id, 5, uses: 2);

        $this->assertNull($response->json('errors'), (string) $response->getContent());
        Queue::assertPushed(GenerateEventInviteCodesJob::class, fn (GenerateEventInviteCodesJob $job) => $job->uses === 2);
    }

    public function testTheJobStoresTheUsesOnEventWideCodes(): void
    {
        $this->runJob(2, uses: 2);

        foreach ($this->passes()->get() as $pass) {
            $this->assertSame(2, (int) $pass->max_uses);
            $this->assertTrue($pass->event_wide);
            $this->assertSame(2, $pass->remainingUses());
        }
    }

    public function testEventWideCodesExpireWithTheLastDayOfTheEvent(): void
    {
        $lastDay = $this->createEventVersion($this->eventVersion->event, startAt: Carbon::now()->addDays(40));

        $this->runJob(1, uses: 2);

        $this->assertSame(
            $lastDay->end_at->toDateString(),
            Carbon::parse($this->passes()->firstOrFail()->expiration_date)->toDateString()
        );
    }

    public function testGraphQLRejectsNonAdmin(): void
    {
        Queue::fake();
        $this->actingAs($this->createUser(), 'api');

        $response = $this->generateMutation($this->eventVersion->event_id, 10);

        $this->assertNotNull($response->json('errors'));
        Queue::assertNotPushed(GenerateEventInviteCodesJob::class);
    }

    public function testGraphQLListsPlainCodesFilteredByStatus(): void
    {
        $this->runJob(3);
        [$first, $redeemed] = $this->passes()->orderBy('id')->get()->all();
        $this->redeem($redeemed);

        $all = $this->listQuery($this->eventVersion->event_id);
        $this->assertNull($all->json('errors'), (string) $all->getContent());
        $this->assertSame(3, $all->json('data.eventInviteCodes.paginatorInfo.total'));
        $this->assertSame($first->plainCode(), $all->json('data.eventInviteCodes.data.0.code'));

        $available = $this->listQuery($this->eventVersion->event_id, 'AVAILABLE');
        $this->assertSame(2, $available->json('data.eventInviteCodes.paginatorInfo.total'));

        $redeemedList = $this->listQuery($this->eventVersion->event_id, 'REDEEMED');
        $this->assertSame(1, $redeemedList->json('data.eventInviteCodes.paginatorInfo.total'));
        $this->assertSame($redeemed->plainCode(), $redeemedList->json('data.eventInviteCodes.data.0.code'));
        $this->assertSame('REDEEMED', $redeemedList->json('data.eventInviteCodes.data.0.status'));
    }

    public function testGraphQLDoesNotListCodesOfAnotherCompany(): void
    {
        $otherCompany = Companies::factory()->create();
        $foreign = $this->createEventVersion($this->createEvent($otherCompany), $otherCompany);

        $response = $this->listQuery($foreign->event_id);

        $this->assertNotNull($response->json('errors'));
    }

    private function runJob(int $quantity, ?string $prefix = null, int $uses = 1): void
    {
        new GenerateEventInviteCodesJob(
            $this->apps,
            $this->user,
            $this->eventVersion,
            $quantity,
            $prefix,
            $uses
        )->handle();
    }

    private function redeem(ParticipantPass $pass): EventVersion
    {
        $booking = $this->createEventVersion($this->createEvent());

        new RedeemPassAction(
            $this->apps,
            $this->company,
            $pass->plainCode(),
            $this->eventVersion,
            $booking
        )->execute();

        return $booking;
    }

    private function passes(): Builder
    {
        return ParticipantPass::query()->where('event_version_id', $this->eventVersion->getId());
    }

    private function generateMutation(int $eventId, int $quantity, ?string $prefix = null, ?int $uses = null): TestResponse
    {
        $prefixArg = ($prefix === null ? '' : ', prefix: "' . $prefix . '"') . ($uses === null ? '' : ', uses: ' . $uses);

        return $this->graphQL(
            'mutation {
                generateEventInviteCodes(input: { event_id: ' . $eventId . ', quantity: ' . $quantity . $prefixArg . ' }) {
                    queued
                    message
                }
            }'
        );
    }

    private function listQuery(int $eventId, ?string $status = null): TestResponse
    {
        $statusArg = $status === null ? '' : ', status: ' . $status;

        return $this->graphQL(
            'query {
                eventInviteCodes(event_id: ' . $eventId . $statusArg . ', first: 100) {
                    data { code uses remaining_uses status created_at }
                    paginatorInfo { total }
                }
            }'
        );
    }
}
