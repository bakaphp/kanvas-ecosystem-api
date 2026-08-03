<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Tests\TestCase;

final class AgentSettingTest extends TestCase
{
    private function makeAgent(?int $companyId = null): Agent
    {
        $app = app(Apps::class);
        $companyId ??= auth()->user()->getCurrentCompany()->getId();

        $agentType = AgentType::factory()->withAppId($app->getId())->create(['provider' => 'neuron']);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($companyId)
            ->create(['agent_type_id' => $agentType->getId()]);
    }

    public function testSetAndDeleteAgentSetting(): void
    {
        $agent = $this->makeAgent();

        $this->graphQL(
            'mutation($input: ModuleConfigInput!) { setAgentSetting(input: $input) }',
            ['input' => ['entity_uuid' => $agent->uuid, 'key' => 'welcome_message', 'value' => 'hello']],
        )->assertJson(['data' => ['setAgentSetting' => true]]);

        $this->assertSame('hello', Agent::getByUuid($agent->uuid, app(Apps::class))->get('welcome_message'));

        $this->graphQL(
            'mutation($input: ModuleConfigInput!) { deleteAgentSetting(input: $input) }',
            ['input' => ['entity_uuid' => $agent->uuid, 'key' => 'welcome_message', 'value' => 'delete']],
        )->assertJson(['data' => ['deleteAgentSetting' => true]]);

        $this->assertNull(Agent::getByUuid($agent->uuid, app(Apps::class))->get('welcome_message'));
    }

    public function testSetAgentSettingAcceptsObjectValue(): void
    {
        $agent = $this->makeAgent();

        $this->graphQL(
            'mutation($input: ModuleConfigInput!) { setAgentSetting(input: $input) }',
            ['input' => ['entity_uuid' => $agent->uuid, 'key' => 'llm_config', 'value' => ['model' => 'opus', 'temp' => 1]]],
        )->assertJson(['data' => ['setAgentSetting' => true]]);

        $this->assertSame(
            ['model' => 'opus', 'temp' => 1],
            Agent::getByUuid($agent->uuid, app(Apps::class))->get('llm_config')
        );
    }

    public function testCannotConfigureAnotherCompanysAgent(): void
    {
        $app = app(Apps::class);
        $otherCompany = Companies::factory()->create();
        $foreignAgent = $this->makeAgent($otherCompany->getId());

        // Acting from the current company, targeting another company's agent → rejected, nothing written.
        $this->graphQL(
            'mutation($input: ModuleConfigInput!) { setAgentSetting(input: $input) }',
            ['input' => ['entity_uuid' => $foreignAgent->uuid, 'key' => 'welcome_message', 'value' => 'hacked']],
        );

        $this->assertNull(
            Agent::getByUuidFromCompanyApp($foreignAgent->uuid, $otherCompany, $app)->get('welcome_message')
        );
    }
}
