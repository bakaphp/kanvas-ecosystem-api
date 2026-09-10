<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Channels\Repositories\ChannelRepository;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * `CreateChannelAction` attaches exactly one user to `channel_users` — whoever created the channel.
 * Membership alone therefore hid an entity's Notes/Activity channels from every other member of the
 * company, including admins and owners, which is how an organization's notes channel existed in the
 * database but never reached the UI.
 */
final class ChannelRepositoryVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'social'];

    private Apps $currentApp;
    private Companies $currentCompany;
    private Users $actingUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->actingUser = auth()->user();
        $this->currentCompany = $this->actingUser->getCurrentCompany();
    }

    public function testAdminSeesACompanyChannelTheyAreNotAMemberOf(): void
    {
        $this->assertTrue($this->actingUser->isAdmin(), 'This case only says anything for an admin.');

        $channel = $this->channelOwnedBySomeoneElse();

        $visible = ChannelRepository::getByIdBuilder(
            $this->actingUser,
            $this->currentApp,
            $this->currentCompany
        )->where('channels.id', $channel->getId())->exists();

        $this->assertTrue($visible, 'An admin must see every channel in their own company.');
    }

    public function testChannelFromAnotherCompanyStaysHidden(): void
    {
        $otherCompany = Companies::factory()->create();

        $channel = $this->channelOwnedBySomeoneElse($otherCompany->getId());

        $visible = ChannelRepository::getByIdBuilder(
            $this->actingUser,
            $this->currentApp,
            $this->currentCompany
        )->where('channels.id', $channel->getId())->exists();

        $this->assertFalse($visible, 'Company scoping is the only thing standing between admins and a cross-tenant read.');
    }

    public function testNonAdminStillOnlySeesChannelsTheyBelongTo(): void
    {
        $member = Users::factory()->create();
        $this->assertFalse($member->isAdmin(), 'This case only says anything for a non-admin.');

        $joined = $this->channelOwnedBySomeoneElse();
        $notJoined = $this->channelOwnedBySomeoneElse();
        $this->attachMember($joined, $member);

        $visible = ChannelRepository::getByIdBuilder($member, $this->currentApp, $this->currentCompany)
            ->whereIn('channels.id', [$joined->getId(), $notJoined->getId()])
            ->pluck('channels.id')
            ->all();

        $this->assertSame([$joined->getId()], $visible, 'Widening visibility for admins must not widen it for everyone.');
    }

    public function testTimestampsAreTheChannelsOwnNotThePivots(): void
    {
        $channel = $this->channelOwnedBySomeoneElse();

        // The pivot needs a timestamp far from the channel's for a bleed to be unmistakable rather
        // than a same-second coincidence.
        $createdAt = now()->subYear()->startOfDay();
        Channel::where('id', $channel->getId())->update(['created_at' => $createdAt]);
        $this->attachMember($channel);

        $loaded = ChannelRepository::getByIdBuilder(
            $this->actingUser,
            $this->currentApp,
            $this->currentCompany
        )->where('channels.id', $channel->getId())->firstOrFail();

        $this->assertSame(
            $createdAt->toDateTimeString(),
            $loaded->created_at->toDateTimeString(),
            'Joining channel_users without selecting channels.* overwrites the channel timestamps with the pivot ones.'
        );
    }

    /**
     * `Channel::users()` is a belongsToMany to `Users`, so Eloquent writes the pivot on the *related*
     * model's connection (`mysql`) while the repository reads it on the channel's (`social`). Under
     * DatabaseTransactions those are two uncommitted transactions that cannot see each other, so the
     * pivot has to be written on `social` directly or the membership is invisible to the query.
     */
    private function attachMember(Channel $channel, ?Users $user = null): void
    {
        DB::connection('social')->table('channel_users')->insert([
            'channel_id' => $channel->getId(),
            'users_id' => ($user ?? $this->actingUser)->getId(),
            'roles_id' => 1,
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function channelOwnedBySomeoneElse(?int $companyId = null): Channel
    {
        return Channel::create([
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $companyId ?? $this->currentCompany->getId(),
            'users_id' => $this->actingUser->getId(),
            'name' => 'Notes',
            'slug' => (string) fake()->unique()->uuid(),
            'description' => 'Channel nobody was ever attached to',
            'entity_id' => (string) fake()->unique()->randomNumber(8),
            'entity_namespace' => Users::class,
        ]);
    }
}
