<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Orchestrator;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Orchestrator\ProjectOrchestratorAgent;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class EnsureOrchestratorAgentCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow'];

    public function testCommandProvisionsOrchestratorForCompany(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        AgentType::factory()->create([
            'apps_id' => 0,
            'name' => 'Project Orchestrator',
            'handler' => ProjectOrchestratorAgent::class,
        ]);

        $exit = Artisan::call('kanvas:nervous-system:ensure-orchestrator-agent', [
            '--app' => $app->getId(),
            '--company' => $company->getId(),
        ]);

        // The command swallows every per-company Throwable into `$failed++`, so a bare exit-code
        // assertion reports "1 is identical to 0" and nothing else. Carry the output into the message.
        $this->assertSame(0, $exit, Artisan::output());

        $agent = Agent::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->whereHas('type', fn ($q) => $q->where('handler', ProjectOrchestratorAgent::class))
            ->first();

        $this->assertNotNull($agent, 'orchestrator agent should be provisioned');
        $this->assertNotNull(
            Project::query()->where('agent_id', $agent->getId())->first(),
            'inbox project should be provisioned',
        );
    }
}
