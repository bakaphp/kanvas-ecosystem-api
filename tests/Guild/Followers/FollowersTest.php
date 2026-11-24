<?php

declare(strict_types=1);

namespace Tests\Guild\Followers;

use Illuminate\Support\Facades\Artisan;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Customers\Actions\MergePeopleAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Followers\Actions\AddFollowerAction;
use Kanvas\Guild\Followers\Actions\RemoveFollowerAction;
use Kanvas\Guild\Followers\Models\Follower;
use Kanvas\Guild\Followers\Repositories\FollowersRepository;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class FollowersTest extends TestCase
{
    public function testFollowingALeadTwiceKeepsOneRow(): void
    {
        $user = auth()->user();
        $lead = $this->makeLead();

        $first = new AddFollowerAction($lead, $user)->execute();
        $second = new AddFollowerAction($lead, $user)->execute();

        $this->assertSame(Lead::class, $first->entity_namespace);
        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame($lead->companies_id, (int) $first->companies_id);
        $this->assertTrue(FollowersRepository::isFollowing($user, $lead));
    }

    public function testUnfollowingThenFollowingAgainRestoresTheSameRow(): void
    {
        $user = auth()->user();
        $people = $this->makeLead()->people;

        $follow = new AddFollowerAction($people, $user)->execute();

        $this->assertSame(People::class, $follow->entity_namespace);
        $this->assertTrue(new RemoveFollowerAction($people, $user)->execute());
        $this->assertFalse(FollowersRepository::isFollowing($user, $people));
        $this->assertFalse(new RemoveFollowerAction($people, $user)->execute());

        $again = new AddFollowerAction($people, $user)->execute();

        $this->assertSame($follow->getId(), $again->getId());
        $this->assertTrue(FollowersRepository::isFollowing($user, $people));
    }

    public function testAUserOutsideTheCompanyCannotFollow(): void
    {
        $people = $this->makeLead()->people;

        $this->expectException(ModelNotFoundException::class);

        new AddFollowerAction($people, Users::factory()->create())->execute();
    }

    public function testFollowersAreListedPerRecord(): void
    {
        $user = auth()->user();
        $followed = $this->makeLead();
        $other = $this->makeLead();

        new AddFollowerAction($followed, $user)->execute();
        new AddFollowerAction($followed->people, $user)->execute();

        $this->assertSame([$user->getId()], FollowersRepository::getFollowersBuilder($followed)->pluck('users.id')->all());
        $this->assertSame([$user->getId()], FollowersRepository::getFollowersBuilder($followed->people)->pluck('users.id')->all());
        $this->assertSame([], FollowersRepository::getFollowersBuilder($other)->pluck('users.id')->all());
        $this->assertSame([], FollowersRepository::getFollowersBuilder($other->people)->pluck('users.id')->all());
    }

    public function testALeadAndAPersonWithTheSameIdDoNotShareFollowers(): void
    {
        $user = auth()->user();
        $lead = $this->makeLead();
        $target = $this->makeLead();

        Follower::query()->create([
            'apps_id' => $lead->apps_id,
            'companies_id' => $lead->companies_id,
            'users_id' => $user->getId(),
            'entity_id' => $lead->getId(),
            'entity_namespace' => People::class,
        ]);

        $this->assertFalse(FollowersRepository::isFollowing($user, $lead));
        $this->assertSame([], FollowersRepository::getFollowersBuilder($lead)->pluck('users.id')->all());
        $this->assertSame(0, FollowersRepository::moveFollowers($lead, $target));
        $this->assertSame(
            1,
            Follower::query()
                ->where('entity_namespace', People::class)
                ->where('entity_id', $lead->getId())
                ->count()
        );

        Follower::query()->where('entity_namespace', People::class)->where('entity_id', $lead->getId())->delete();
    }

    public function testMergingPeopleMovesFollowersWithoutDuplicatingThem(): void
    {
        $user = auth()->user();
        $colleague = Users::factory()->create();
        $source = $this->makeLead()->people;
        $target = $this->makeLead()->people;

        new AddFollowerAction($source, $user)->execute();
        new AddFollowerAction($target, $user)->execute();
        Follower::query()->create([
            'apps_id' => $source->apps_id,
            'companies_id' => $source->companies_id,
            'users_id' => $colleague->getId(),
            'entity_id' => $source->getId(),
            'entity_namespace' => People::class,
        ]);

        new MergePeopleAction(
            source: $source,
            target: $target,
            user: $user,
        )->execute();

        $this->assertSame(0, FollowersRepository::forEntity($source)->count());
        $this->assertEqualsCanonicalizing(
            [$user->getId(), $colleague->getId()],
            FollowersRepository::forEntity($target)->pluck('users_id')->all()
        );
    }

    public function testAMergeKeepsAnActiveFollowOverAnUnfollowedOne(): void
    {
        $user = auth()->user();
        $sourceLead = $this->makeLead();
        $targetLead = $this->makeLead();

        foreach ([[$sourceLead, $targetLead], [$sourceLead->people, $targetLead->people]] as [$source, $target]) {
            new AddFollowerAction($source, $user)->execute();
            new AddFollowerAction($target, $user)->execute();
            new RemoveFollowerAction($target, $user)->execute();

            $this->assertSame(1, FollowersRepository::moveFollowers($source, $target));
            $this->assertTrue(FollowersRepository::isFollowing($user, $target));
            $this->assertFalse(FollowersRepository::isFollowing($user, $source));
        }
    }

    public function testFollowPeopleAcceptsAUuid(): void
    {
        $user = auth()->user();
        $people = $this->makeLead()->people;

        $this->graphQL('
            mutation($input: FollowInput!) {
                followPeople(input: $input)
            }
        ', ['input' => ['entity_id' => $people->uuid, 'user_id' => $user->getId()]])
            ->assertJson(['data' => ['followPeople' => true]]);

        $this->assertTrue(FollowersRepository::isFollowing($user, $people));
    }

    public function testBackfillCopiesSocialLeadFollowsOnce(): void
    {
        $user = auth()->user();
        $lead = $this->makeLead();
        $user->follow($lead);

        Artisan::call('guild:backfill-lead-followers', ['--app' => $lead->apps_id]);
        Artisan::call('guild:backfill-lead-followers', ['--app' => $lead->apps_id]);

        $this->assertSame(
            1,
            FollowersRepository::forEntity($lead)->where('users_id', $user->getId())->count()
        );

        UsersFollows::query()
            ->where('entity_namespace', Lead::class)
            ->where('entity_id', $lead->getId())
            ->delete();
    }

    public function testFollowAndUnFollowPeopleThroughGraphQL(): void
    {
        $user = auth()->user();
        $people = $this->makeLead()->people;
        $input = ['entity_id' => $people->getId(), 'user_id' => $user->getId()];

        $this->graphQL('
            mutation($input: FollowInput!) {
                followPeople(input: $input)
            }
        ', ['input' => $input])->assertJson(['data' => ['followPeople' => true]]);

        $this->graphQL('
            query($where: QueryPeoplesWhereWhereConditions) {
                peoples(where: $where) {
                    data {
                        id
                        followers {
                            data {
                                id
                            }
                        }
                    }
                }
            }
        ', ['where' => ['column' => 'ID', 'operator' => 'EQ', 'value' => $people->getId()]])
            ->assertJsonPath('data.peoples.data.0.followers.data.0.id', (string) $user->getId());

        $this->graphQL('
            mutation($input: FollowInput!) {
                unFollowPeople(input: $input)
            }
        ', ['input' => $input])->assertJson(['data' => ['unFollowPeople' => true]]);

        $this->assertFalse(FollowersRepository::isFollowing($user, $people));
    }

    public function testLeadFollowersComeFromTheGuildTableThroughGraphQL(): void
    {
        $user = auth()->user();
        $lead = $this->makeLead();
        $input = ['entity_id' => $lead->uuid, 'user_id' => $user->getId()];

        $this->graphQL('
            mutation($input: FollowInput!) {
                followLead(input: $input)
            }
        ', ['input' => $input])->assertJson(['data' => ['followLead' => true]]);

        $this->assertTrue(FollowersRepository::isFollowing($user, $lead));

        $this->graphQL('
            query($where: QueryLeadsWhereWhereConditions) {
                leads(where: $where) {
                    data {
                        followers {
                            data {
                                id
                            }
                        }
                    }
                }
            }
        ', ['where' => ['column' => 'ID', 'operator' => 'EQ', 'value' => $lead->getId()]])
            ->assertJsonPath('data.leads.data.0.followers.data.0.id', (string) $user->getId());

        $this->graphQL('
            mutation($input: FollowInput!) {
                unFollowLead(input: $input)
            }
        ', ['input' => $input])->assertJson(['data' => ['unFollowLead' => true]]);

        $this->assertFalse(FollowersRepository::isFollowing($user, $lead));
    }

    private function makeLead(): Lead
    {
        $user = auth()->user();

        return Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create([
                'leads_owner_id' => $user->getId(),
                'users_id' => $user->getId(),
                'companies_branches_id' => $user->getCurrentBranch()->getId(),
            ]);
    }
}
