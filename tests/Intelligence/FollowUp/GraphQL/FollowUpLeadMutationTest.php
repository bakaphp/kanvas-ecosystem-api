<?php

declare(strict_types=1);

namespace Tests\Intelligence\FollowUp\GraphQL;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\AgentEnum;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\Stubs\FollowUp\FollowUpAgentStub;
use Tests\TestCase;

/**
 * Verifies the `followUpLead(leadId: ID!)` GraphQL mutation:
 *   - @guardByAdmin passes (the test user is auto-authenticated as admin per TestCase)
 *   - Returns FollowUpLeadOutcome shape { kind, reason, message }
 *   - Runs with force=true (no need to wait out the silence interval)
 *
 * Stage has no follow_up config in this test → outcome.kind=skipped,
 * reason=follow_up_disabled. That's enough to prove the mutation wiring +
 * resolver invoke the action and return its outcome.
 */
class FollowUpLeadMutationTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testFollowUpLeadMutationReturnsOutcomeShape(): void
    {
        Http::fake();
        FollowUpAgentStub::reset();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $user->getId());

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'tests']
        );

        // Need a FOLLOW_UP_ENGAGER agent to exist or Agent::getByName throws.
        $agentType = AgentType::factory()->withAppId($app->getId())
            ->create(['provider' => 'neuron', 'handler' => FollowUpAgentStub::class]);
        Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'name' => AgentEnum::FOLLOW_UP_ENGAGER->value,
            'agent_type_id' => $agentType->getId(),
            'user_id' => $user->getId(),
            'role' => ['background' => [], 'steps' => [], 'output' => ''],
        ]);

        $pipeline = Pipeline::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'system_modules_id' => 0,
            'name' => 'P',

            'is_default' => 0,
        ]);
        // Stage with NO follow_up config → action returns skipped(follow_up_disabled).
        $stage = PipelineStage::create([
            'pipelines_id' => $pipeline->getId(),
            'name' => 'S',
            'weight' => 1,
            'config' => null,
        ]);

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'pipeline_id' => $pipeline->getId(),
            'pipeline_stage_id' => $stage->getId(),
        ]);

        $this->graphQL('
            mutation($leadId: ID!) {
                followUpLead(leadId: $leadId) {
                    kind
                    reason
                    message
                }
            }
        ', ['leadId' => (string) $lead->getId()])
            ->assertSuccessful()
            ->assertJson([
                'data' => [
                    'followUpLead' => [
                        'kind' => 'SKIPPED',
                        'reason' => 'follow_up_disabled',
                        'message' => null,
                    ],
                ],
            ]);
    }
}
