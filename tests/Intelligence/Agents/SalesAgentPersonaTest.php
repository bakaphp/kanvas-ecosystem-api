<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithCustomer;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithUser;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class SalesAgentPersonaTest extends TestCase
{
    private function makeSalesAgent(Users $agentUser): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'user_id' => $agentUser->getId(),
                'role' => [
                    'background' => 'You help customers find a car.',
                    'steps' => 'Be helpful.',
                    'output' => 'Plain text.',
                ],
            ]);
    }

    public function testInstructionsCarryThePersonaNameButLeakNoInternalIds(): void
    {
        $agentUser = Users::factory()->create(['firstname' => 'Sarah', 'lastname' => 'Sales']);
        $agent = $this->makeSalesAgent($agentUser);

        $handler = new SalesAgent();
        $handler->setConfiguration(agent: $agent, user: auth()->user());

        $instructions = $handler->instructions();

        // Knows its own name...
        $this->assertStringContainsString('Sarah Sales', $instructions);
        // ...and is told to keep internal identifiers away from the customer.
        $this->assertStringContainsString('NEVER reveal internal system identifiers', $instructions);
        // ...and the persona block itself never carries the agent's email/user id.
        $this->assertStringNotContainsString((string) $agentUser->email, $instructions);
    }

    public function testPersonaFallsBackToTheAgentNameWhenTheUserHasNoName(): void
    {
        $agentUser = Users::factory()->create(['firstname' => '', 'lastname' => '', 'displayname' => 'closer-bot']);
        $agent = $this->makeSalesAgent($agentUser);

        $handler = new SalesAgent();
        $handler->setConfiguration(agent: $agent, user: auth()->user());

        $this->assertStringContainsString('closer-bot', $handler->instructions());
    }

    public function testSalesAgentIsMarkedCustomerFacingNotInternal(): void
    {
        $handler = new SalesAgent();

        $this->assertInstanceOf(ConversesWithCustomer::class, $handler);
        $this->assertNotInstanceOf(ConversesWithUser::class, $handler);
    }

    public function testAgentConversesWithCustomerReflectsTheHandler(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $type = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['provider' => 'neuron', 'handler' => SalesAgent::class]);
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $type->getId()]);

        $this->assertTrue($agent->conversesWithCustomer());
        $this->assertFalse($agent->conversesWithUser());
    }
}
