<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Neuron\Traits\HasKanvasAgentBehavior;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\NotifyPlanOwnerOfBlockedPlanJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * A plan inside a project has a PM watching it. A plan created on its own has nobody — which is
 * exactly when a silent block is worst, because a person made it and is waiting on it.
 */
final class BlockedPlanEscalationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    public function testAPlanWithNoProjectStillTellsItsOwnerWhenItBlocks(): void
    {
        Bus::fake([NotifyPlanOwnerOfBlockedPlanJob::class]);

        $plan = $this->plan();
        $this->assertNull($plan->project_id, 'The premise is a plan created outside any project.');

        $plan->update(['status' => PlanStatusEnum::BLOCKED->value]);

        Bus::assertDispatched(
            NotifyPlanOwnerOfBlockedPlanJob::class,
            fn (NotifyPlanOwnerOfBlockedPlanJob $job): bool => $job->plan->getId() === $plan->getId(),
        );
    }

    public function testAPlanThatMovesToAnyOtherStatusEscalatesNothing(): void
    {
        Bus::fake([NotifyPlanOwnerOfBlockedPlanJob::class]);

        $this->plan()->update(['status' => PlanStatusEnum::DONE->value]);

        Bus::assertNotDispatched(NotifyPlanOwnerOfBlockedPlanJob::class);
    }

    /**
     * The whole point of the alert is that a human hears about it, so the owner has to survive to the
     * job — a plan with no owner is the one case where there is genuinely nobody to tell.
     */
    public function testTheOwnerIsCarriedThroughToTheJob(): void
    {
        Bus::fake([NotifyPlanOwnerOfBlockedPlanJob::class]);

        $plan = $this->plan();
        $plan->update(['status' => PlanStatusEnum::BLOCKED->value]);

        Bus::assertDispatched(
            NotifyPlanOwnerOfBlockedPlanJob::class,
            fn (NotifyPlanOwnerOfBlockedPlanJob $job): bool
                => $job->plan->user?->getId() === $this->currentUser()->getId(),
        );
    }

    /**
     * The refusal that started this: an agent told a human to find "an engineering agent or developer
     * with access to workflow orchestrator tools like n8n/Zapier" — on the platform that orchestrates.
     */
    public function testEveryAgentIsToldKanvasIsTheOrchestrator(): void
    {
        $context = implode(' ', HasKanvasAgentBehavior::platformContext());

        $this->assertStringContainsString('Kanvas is the orchestrator', $context);
        $this->assertStringContainsString('workflow engine', $context);
        $this->assertStringContainsString('Zapier', $context, 'The named tools are what it must not reach for.');
        $this->assertStringContainsString('n8n', $context);
        $this->assertStringContainsString(
            'which Kanvas tool or permission you are missing',
            $context,
            'A refusal has to name what would unblock it, not who to reassign to.'
        );
    }

    private function plan(): Plan
    {
        return new CreatePlanAction(
            new PlanData(
                app: $this->app(),
                company: $this->company(),
                title: 'Standalone ' . fake()->unique()->lexify('?????'),
                planType: 'project_work',
                user: $this->currentUser(),
                status: PlanStatusEnum::ACTIVE,
            ),
        )->execute();
    }

    private function app(): Apps
    {
        return app(Apps::class);
    }

    private function company(): Companies
    {
        return $this->currentUser()->getCurrentCompany();
    }

    private function currentUser(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }
}
