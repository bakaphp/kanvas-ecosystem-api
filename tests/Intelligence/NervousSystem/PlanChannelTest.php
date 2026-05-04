<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\DeletePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Tests\TestCase;

class PlanChannelTest extends TestCase
{
    public function testCreatingAPlanCreatesADefaultChannel(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Plan with channel',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::DRAFT,
            ),
        )->execute();

        $channels = $plan->socialChannels;

        $this->assertGreaterThanOrEqual(1, $channels->count());
        $first = $channels->first();
        $this->assertNotNull($first);
        $this->assertSame((string) $plan->id, (string) $first->entity_id);
    }

    public function testDeletingAPlanCascadesToItsChannels(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Plan to delete with channel',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::DRAFT,
            ),
        )->execute();

        $channel = $plan->socialChannels->first();
        $this->assertNotNull($channel);
        $channelId = $channel->id;

        new DeletePlanAction($plan)->execute();

        $plan->refresh();
        $stillActive = $plan->socialChannels()->where('id', $channelId)->where('is_deleted', 0)->count();

        $this->assertSame(0, $stillActive);
    }
}
