<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Orchestrator;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Orchestrator\ProjectOrchestratorAgent;
use Kanvas\Users\Jobs\OnBoardingJob;
use Kanvas\Users\Models\Users;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Serial: toggling an onboarding flag on the shared test app is global. `HashTableTrait::set()` writes
 * to Redis and upserts on the `ecosystem` connection, and neither is rolled back by
 * DatabaseTransactions — so in the parallel lane the flag turns onboarding orchestration on for every
 * other process for as long as this test runs, and their user-creating tests silently start
 * provisioning orchestrators.
 */
#[Group('serial')]
class OnboardingOrchestratorProvisionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow'];

    /**
     * @return array{0: Apps, 1: Companies, 2: Users}
     */
    private function context(): array
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        return [$app, $user->getCurrentCompany(), $user];
    }

    private function orchestratorAgent(Apps $app, Companies $company): ?Agent
    {
        return Agent::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->where('name', 'Project Orchestrator')
            ->notDeleted()
            ->first();
    }

    public function testOnboardingProvisionsOrchestratorWhenFlagOn(): void
    {
        [$app, $company, $user] = $this->context();
        AgentType::factory()->create([
            'apps_id' => 0,
            'name' => 'Project Orchestrator',
            'handler' => ProjectOrchestratorAgent::class,
        ]);

        $before = $this->orchestratorAgent($app, $company);

        $app->set(AppSettingsEnums::ONBOARDING_ORCHESTRATOR_SETUP->getValue(), 1);

        try {
            new OnBoardingJob($user, $company->branch, $app)->handle();
        } finally {
            $app->set(AppSettingsEnums::ONBOARDING_ORCHESTRATOR_SETUP->getValue(), 0);
        }

        $after = $this->orchestratorAgent($app, $company);
        $this->assertNotNull($after, 'orchestrator should be provisioned during onboarding when the flag is on');

        // Idempotent: if one already existed (committed by another run), it is reused, not duplicated.
        if ($before !== null) {
            $this->assertSame($before->getId(), $after->getId());
        }
    }

    public function testOnboardingSkipsOrchestratorWhenFlagOff(): void
    {
        [$app, $company, $user] = $this->context();

        // All onboarding flags default off → the job early-returns without provisioning.
        $before = $this->orchestratorAgent($app, $company)?->getId();

        new OnBoardingJob($user, $company->branch, $app)->handle();

        $after = $this->orchestratorAgent($app, $company)?->getId();
        $this->assertSame($before, $after, 'no orchestrator should be created when the flag is off');
    }
}
