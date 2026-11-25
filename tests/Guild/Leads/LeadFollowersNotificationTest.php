<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\FollowLeadOnMessageAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\NotifyLeadStakeholdersService;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class LeadFollowersNotificationTest extends TestCase
{
    public function testAColleagueWhoMessagesTheLeadBecomesAFollowerOnce(): void
    {
        $sender = auth()->user();
        $lead = $this->makeLead(Users::factory()->create());

        $follow = new FollowLeadOnMessageAction($lead, $sender)->execute();
        $again = new FollowLeadOnMessageAction($lead, $sender)->execute();

        $this->assertInstanceOf(UsersFollows::class, $follow);
        $this->assertSame($follow->getId(), $again->getId());
        $this->assertSame(1, $this->followRows($lead, $sender));
    }

    public function testMessagingTheLeadAlsoFollowsItsPerson(): void
    {
        $sender = auth()->user();
        $lead = $this->makeLead(Users::factory()->create());

        new FollowLeadOnMessageAction($lead, $sender)->execute();
        new FollowLeadOnMessageAction($lead, $sender)->execute();

        $this->assertSame(1, $this->followRows($lead->people, $sender));
    }

    public function testFollowAndUnFollowPeopleThroughGraphQL(): void
    {
        $user = auth()->user();
        $people = $this->makeLead($user)->people;

        foreach ([$people->getId(), $people->uuid] as $entityId) {
            $input = ['entity_id' => $entityId, 'user_id' => $user->getId()];

            $this->graphQL('
                mutation($input: FollowInput!) {
                    followPeople(input: $input)
                }
            ', ['input' => $input])->assertJson(['data' => ['followPeople' => true]]);

            $this->graphQL('
                query($where: QueryPeoplesWhereWhereConditions) {
                    peoples(where: $where) {
                        data {
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

            $this->assertSame(0, $this->followRows($people, $user));
        }
    }

    public function testTheOwnerAndTheCreatorAreNotAddedAsFollowers(): void
    {
        $sender = auth()->user();
        $stranger = Users::factory()->create();

        $owned = $this->makeLead($sender, $stranger);
        $created = $this->makeLead($stranger, $sender);

        $this->assertNull(new FollowLeadOnMessageAction($owned, $sender)->execute());
        $this->assertNull(new FollowLeadOnMessageAction($created, $sender)->execute());
        $this->assertSame(0, $this->followRows($owned, $sender) + $this->followRows($created, $sender));
    }

    public function testASenderOutsideTheLeadCompanyIsNotAdded(): void
    {
        $outsider = Users::factory()->create();
        $lead = $this->makeLead(Users::factory()->create());

        $this->assertNull(new FollowLeadOnMessageAction($lead, $outsider)->execute());
        $this->assertSame(0, $this->followRows($lead, $outsider));
    }

    public function testFollowersAreNotifiedWhenSomeoneReplies(): void
    {
        $follower = auth()->user();
        $lead = $this->makeLead(Users::factory()->create());
        $lead->company->set(NotifyLeadStakeholdersService::NOTIFY_ON_HUMAN_REPLY, 1);
        new FollowLeadOnMessageAction($lead, $follower)->execute();

        $message = Message::factory()->create();
        Notification::fake();

        $result = new NotifyLeadStakeholdersService($lead)->onAgentReply($message, isHuman: true);

        Notification::assertSentTo($follower, Blank::class);
        $this->assertContains($follower->getId(), $result['manager_ids']);
    }

    public function testOnlyRecipientsInSeveralCompaniesGetTheCompanyName(): void
    {
        $follower = auth()->user();
        $owner = $this->createUser();
        $lead = $this->makeLead($owner);
        $lead->company->set(NotifyLeadStakeholdersService::NOTIFY_ON_HUMAN_REPLY, 1);
        new FollowLeadOnMessageAction($lead, $follower)->execute();
        Companies::factory()->create()->associateUserApp($follower, app(Apps::class), 1);

        $message = Message::factory()->create();
        Notification::fake();

        new NotifyLeadStakeholdersService($lead)->onAgentReply($message, isHuman: true);

        Notification::assertSentTo(
            $follower,
            Blank::class,
            fn (Blank $sent) => $sent->getData()['company_name'] === $lead->company->name
                && str_ends_with((string) $sent->getSubject(), ' from ' . $lead->company->name)
        );
        Notification::assertSentTo(
            $owner,
            Blank::class,
            fn (Blank $sent) => $sent->getData()['company_name'] === ''
        );
    }

    public function testStakeholderNotificationsCarryTheCompanyNameForMultiCompanyRecipients(): void
    {
        $follower = auth()->user();
        $owner = $this->createUser();
        $lead = $this->makeLead($owner);
        new FollowLeadOnMessageAction($lead, $follower)->execute();
        Companies::factory()->create()->associateUserApp($follower, app(Apps::class), 1);
        Notification::fake();

        $notification = new Blank(
            'lead-manager-notification',
            [],
            ['mail'],
            $lead
        );
        $notification->setSubject('Submitted - Credit App');

        $service = new NotifyLeadStakeholdersService($lead, $notification);
        $service->owner();
        $service->followers();

        Notification::assertSentTo(
            $follower,
            Blank::class,
            fn (Blank $sent) => $sent->getData()['company_name'] === $lead->company->name
                && $sent->getSubject() === 'Submitted - Credit App from ' . $lead->company->name
        );
        Notification::assertSentTo(
            $owner,
            Blank::class,
            fn (Blank $sent) => $sent->getData()['company_name'] === ''
                && $sent->getSubject() === 'Submitted - Credit App'
        );
    }

    public function testAnotherLeadsFollowersAreNotNotified(): void
    {
        $follower = auth()->user();
        $followed = $this->makeLead(Users::factory()->create());
        $other = $this->makeLead(Users::factory()->create());
        new FollowLeadOnMessageAction($followed, $follower)->execute();
        Notification::fake();

        $notification = new Blank(
            'lead-manager-notification',
            [],
            ['mail'],
            $other
        );

        new NotifyLeadStakeholdersService($other, $notification)->followers();

        Notification::assertNothingSentTo($follower);
    }

    private function followRows(Lead|People $entity, Users $user): int
    {
        return UsersFollows::query()
            ->where('entity_namespace', $entity::class)
            ->where('entity_id', $entity->getId())
            ->where('users_id', $user->getId())
            ->where('is_deleted', 0)
            ->count();
    }

    private function makeLead(Users $owner, ?Users $creator = null): Lead
    {
        return Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create([
                'leads_owner_id' => $owner->getId(),
                'users_id' => ($creator ?? $owner)->getId(),
            ]);
    }
}
