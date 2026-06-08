<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Events\PlanBroadcast;
use Kanvas\NervousSystem\Plan\Listeners\NotifyPlanCreatorOfAgentProgressListener;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Notifications\PlanProgressNotification;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * The plan's human owner is told when the agent moves work on the board (a fromSync change). Human
 * edits don't self-notify, and board-created plans (owned by the agent's own user) notify no one.
 */
final class NotifyPlanCreatorOfAgentProgressListenerTest extends TestCase
{
    public function testNotifiesHumanOwnerOnAgentSyncUpdate(): void
    {
        Bus::fake();
        Notification::fake();

        $owner = auth()->user();
        $plan = $this->plan(agentUserId: Users::factory()->create()->getId(), ownerId: $owner->getId());

        new NotifyPlanCreatorOfAgentProgressListener()->handle(
            new PlanBroadcast($plan, PlanChangeTypeEnum::UPDATED, fromSync: true),
        );

        Notification::assertSentTo($owner, PlanProgressNotification::class);
    }

    public function testIgnoresHumanDrivenChange(): void
    {
        Bus::fake();
        Notification::fake();

        $owner = auth()->user();
        $plan = $this->plan(agentUserId: Users::factory()->create()->getId(), ownerId: $owner->getId());

        new NotifyPlanCreatorOfAgentProgressListener()->handle(
            new PlanBroadcast($plan, PlanChangeTypeEnum::UPDATED, fromSync: false),
        );

        Notification::assertNothingSent();
    }

    public function testSkipsSelfNotificationWhenOwnerIsAgentUser(): void
    {
        Bus::fake();
        Notification::fake();

        $owner = auth()->user();
        // Owner IS the agent's user (board-created style) → no human to notify.
        $plan = $this->plan(agentUserId: $owner->getId(), ownerId: $owner->getId());

        new NotifyPlanCreatorOfAgentProgressListener()->handle(
            new PlanBroadcast($plan, PlanChangeTypeEnum::UPDATED, fromSync: true),
        );

        Notification::assertNothingSent();
    }

    private function plan(int $agentUserId, int $ownerId): Plan
    {
        $app = app(Apps::class);
        $owner = Users::getById($ownerId);
        $company = $owner->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['name' => 'progress-agent', 'user_id' => $agentUserId]);

        return new CreatePlanAction(
            new PlanData(app: $app, company: $company, title: 'p', planType: 'test', agent: $agent, user: $owner),
        )->execute();
    }
}
