<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Plan\Actions\PlanContinuationAction;
use Kanvas\NervousSystem\Plan\Enums\ContinuationDecisionEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanLoopConfigEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Services\PlanBudgetService;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

class PlanBudgetTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'intelligence'];

    private mixed $originalCap = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalCap = app(Apps::class)->get(PlanLoopConfigEnum::MAX_COST_USD->value);
    }

    protected function tearDown(): void
    {
        app(Apps::class)->set(PlanLoopConfigEnum::MAX_COST_USD->value, $this->originalCap);
        parent::tearDown();
    }

    /** Unlimited is the default on purpose — a cap nobody tuned kills real work in week one. */
    public function test_no_cap_is_configured_by_default(): void
    {
        app(Apps::class)->set(PlanLoopConfigEnum::MAX_COST_USD->value, '');

        $plan = $this->makePlan();

        $this->assertNull(new PlanBudgetService()->capUsd($plan));
        $this->assertNull(new PlanBudgetService()->exceededReason($plan));
    }

    public function test_a_plan_with_no_session_has_spent_nothing(): void
    {
        $spend = new PlanBudgetService()->spend($this->makePlan());

        $this->assertSame(0, $spend['tokens']);
        $this->assertSame(0.0, $spend['cost_usd']);
    }

    /** With a cap set and zero spend the plan must still run — the guard only stops overspend. */
    public function test_an_unspent_plan_is_not_stopped_by_a_configured_cap(): void
    {
        app(Apps::class)->set(PlanLoopConfigEnum::MAX_COST_USD->value, '5.00');

        $plan = $this->makePlan();
        $this->makeTask($plan, TaskStatusEnum::PENDING);

        $this->assertNull(new PlanBudgetService()->exceededReason($plan));
        $this->assertSame(
            ContinuationDecisionEnum::DISPATCH,
            new PlanContinuationAction($plan)->execute()->verdict,
        );
    }

    /** Overspend outranks open work, the same way the wake budget does. */
    public function test_an_overspent_plan_abandons_even_with_work_left(): void
    {
        $plan = $this->makePlan();
        $this->makeTask($plan, TaskStatusEnum::PENDING);

        $decision = new PlanContinuationAction($plan, new AlwaysOverBudget())->execute();

        $this->assertSame(ContinuationDecisionEnum::ABANDON, $decision->verdict);
        $this->assertStringContainsString('ceiling', $decision->reason);
    }
}

/** Stands in for a plan that has burned through its ceiling, without seeding priced usage rows. */
class AlwaysOverBudget extends PlanBudgetService
{
    public function exceededReason(Plan $plan): ?string
    {
        return 'Plan spend $12.00 reached its $10.00 ceiling.';
    }
}
