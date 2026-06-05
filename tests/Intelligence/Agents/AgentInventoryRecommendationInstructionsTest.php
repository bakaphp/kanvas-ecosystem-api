<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Laravel\Inventory\AgentInventoryRecommendation;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Tests\TestCase;

class AgentInventoryRecommendationInstructionsTest extends TestCase
{
    public function testFallsBackToInCodeDefaultWhenRecordHasNoInstructions(): void
    {
        $agent = $this->makeAgentRecord();

        $handler = new AgentInventoryRecommendation();
        $handler->setConfiguration($agent);

        $this->assertStringContainsString(
            'gift-recommendation engine',
            (string) $handler->instructions(),
        );
    }

    public function testDbInstructionsOverrideTheInCodeDefault(): void
    {
        $agent = $this->makeAgentRecord(instructions: 'CUSTOM PROMPT FROM DB');

        $handler = new AgentInventoryRecommendation();
        $handler->setConfiguration($agent);

        $result = (string) $handler->instructions();

        $this->assertStringContainsString('CUSTOM PROMPT FROM DB', $result);
        $this->assertStringNotContainsString('inventory product-recommendation engine', $result);
    }

    public function testInheritsInstructionsFromAgentTypeWhenAgentBlank(): void
    {
        $agent = $this->makeAgentRecord(typeInstructions: 'PROMPT FROM TYPE');

        $handler = new AgentInventoryRecommendation();
        $handler->setConfiguration($agent);

        $this->assertStringContainsString('PROMPT FROM TYPE', (string) $handler->instructions());
    }

    private function makeAgentRecord(
        ?string $instructions = null,
        ?string $typeInstructions = null,
    ): Agent {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $type = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['instructions' => $typeInstructions]);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'agent_type_id' => $type->getId(),
                'instructions' => $instructions,
            ]);
    }
}
