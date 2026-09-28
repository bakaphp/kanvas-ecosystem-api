<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Actions\ApprovePlanAction;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\UpdatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Support\ProjectBoardColumns;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * Only a board drag writes board_column_key. Every other writer of `status` has to leave the card in
 * a column that still matches the plan, or the board quietly lies about where the work is.
 */
final class PlanBoardColumnSyncTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    public function testCompletingAPlanMovesItsCardToTheDoneColumn(): void
    {
        $project = $this->project();
        $plan = $this->planInColumn($project, 'todo');

        new UpdatePlanAction(
            $plan,
            PlanData::forUpdate($plan, $this->app(), $this->company(), ['status' => 'done']),
        )->execute();

        $this->assertSame('done', $plan->fresh()->board_column_key);
    }

    /**
     * ApprovePlanAction writes `status` straight onto the model — the case a fix living in
     * UpdatePlanAction would have missed.
     */
    public function testApprovingAPlanMovesItsCardOutOfTheApprovalColumn(): void
    {
        $project = $this->project();
        $plan = $this->planInColumn($project, 'todo', PlanStatusEnum::AWAITING_APPROVAL);

        new ApprovePlanAction(
            plan: $plan,
            reviewer: $this->currentUser(),
            approved: true,
            allowSelfApproval: true,
        )->execute();

        $this->assertSame('in_progress', $plan->fresh()->board_column_key);
    }

    public function testAStatusWithNoColumnOfItsOwnFallsBackToLegacyStatuses(): void
    {
        $project = $this->project();
        $plan = $this->planInColumn($project, 'todo');

        $plan->update(['status' => PlanStatusEnum::CANCELLED->value]);

        $this->assertSame(
            'done',
            $plan->fresh()->board_column_key,
            'No column maps plan_status=cancelled, but Done carries it in legacy_statuses.',
        );
    }

    public function testAnExplicitMoveWinsOverRepointing(): void
    {
        $project = $this->project();
        $columns = new ProjectBoardColumns();
        $second = $columns->create($project, 'QA', PlanStatusEnum::ACTIVE->value);
        $plan = $this->planInColumn($project, 'todo');

        $plan->update([
            'status' => PlanStatusEnum::ACTIVE->value,
            'board_column_key' => $second['key'],
        ]);

        $this->assertSame(
            $second['key'],
            $plan->fresh()->board_column_key,
            'in_progress also maps to active, and the leftmost match must not steal the move.',
        );
    }

    public function testASaveThatDoesNotChangeStatusMovesNothing(): void
    {
        $project = $this->project();
        $qa = new ProjectBoardColumns()->create($project, 'QA', PlanStatusEnum::ACTIVE->value);
        $plan = $this->planInColumn($project, $qa['key'], PlanStatusEnum::ACTIVE);

        $plan->update(['priority' => 5, 'status' => PlanStatusEnum::ACTIVE->value]);

        $this->assertSame($qa['key'], $plan->fresh()->board_column_key);
    }

    public function testACardAlreadyInAMatchingColumnIsNotDraggedToTheLeftmostOne(): void
    {
        $project = $this->project();
        $qa = new ProjectBoardColumns()->create($project, 'QA', PlanStatusEnum::ACTIVE->value);
        $plan = $this->planInColumn($project, $qa['key'], PlanStatusEnum::DRAFT);

        $plan->update(['status' => PlanStatusEnum::ACTIVE->value]);

        $this->assertSame(
            $qa['key'],
            $plan->fresh()->board_column_key,
            'in_progress maps to active too, but the card is already in a column that covers it.',
        );
    }

    public function testAPlanThatIsNotOnTheBoardIsLeftAlone(): void
    {
        $plan = $this->plan($this->project());
        $this->assertNull($plan->board_column_key, 'The premise is a plan never dragged onto the board.');

        $plan->update(['status' => PlanStatusEnum::DONE->value]);

        $this->assertNull($plan->fresh()->board_column_key);
    }

    private function planInColumn(
        Project $project,
        string $columnKey,
        PlanStatusEnum $status = PlanStatusEnum::DRAFT,
    ): Plan {
        $plan = $this->plan($project, $status);
        $plan->board_column_key = $columnKey;
        $plan->saveOrFail();

        return $plan;
    }

    private function plan(Project $project, PlanStatusEnum $status = PlanStatusEnum::DRAFT): Plan
    {
        return new CreatePlanAction(
            new PlanData(
                app: $this->app(),
                company: $this->company(),
                title: 'Board sync ' . fake()->unique()->lexify('?????'),
                planType: 'project_work',
                user: $this->currentUser(),
                status: $status,
                project: $project,
            ),
        )->execute();
    }

    private function project(): Project
    {
        $agent = Agent::factory()
            ->withAppId($this->app()->getId())
            ->withCompanyId($this->company()->getId())
            ->create(['user_id' => $this->currentUser()->getId()]);

        return new CreateProjectAction(
            ProjectData::from(
                $this->app(),
                $this->currentUser(),
                $this->company(),
                ['title' => 'Board sync ' . fake()->unique()->lexify('?????'), 'agent_id' => $agent->id],
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
