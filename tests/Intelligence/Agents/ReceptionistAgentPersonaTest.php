<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithCustomer;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithUser;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\CRM\ReceptionistAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CalendarEventTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FaqLookupTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\StopContactTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\InventorySearchTool;
use Kanvas\Users\Models\Users;
use ReflectionMethod;
use Tests\TestCase;

class ReceptionistAgentPersonaTest extends TestCase
{
    private function makeReceptionistAgent(Users $agentUser, array $attributes = []): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(array_merge([
                'user_id' => $agentUser->getId(),
            ], $attributes));
    }

    public function testInstructionsCarryThePersonaNameButLeakNoInternalIds(): void
    {
        $agentUser = Users::factory()->create(['firstname' => 'Rex', 'lastname' => 'Reception']);
        $agent = $this->makeReceptionistAgent($agentUser, [
            'role' => [
                'background' => 'You greet visitors and book appointments.',
                'steps' => 'Be helpful.',
                'output' => 'Plain text.',
            ],
        ]);

        $handler = new ReceptionistAgent();
        $handler->setConfiguration(agent: $agent, user: auth()->user());

        $instructions = $handler->instructions();

        $this->assertStringContainsString('Rex Reception', $instructions);
        $this->assertStringContainsString('NEVER reveal internal system identifiers', $instructions);
        $this->assertStringNotContainsString((string) $agentUser->email, $instructions);
    }

    public function testUsesLocalDefaultPersonaWhenNoRoleIsConfigured(): void
    {
        $agentUser = Users::factory()->create(['firstname' => 'Rex', 'lastname' => 'Reception']);
        $agent = $this->makeReceptionistAgent($agentUser, ['role' => []]);

        $handler = new ReceptionistAgent();
        $handler->setConfiguration(agent: $agent, user: auth()->user());

        $instructions = $handler->instructions();

        $this->assertStringContainsString('receptionist', $instructions);
        $this->assertStringContainsString('get_company_faqs', $instructions);
    }

    public function testReceptionistIsCustomerFacingNotInternal(): void
    {
        $handler = new ReceptionistAgent();

        $this->assertInstanceOf(ConversesWithCustomer::class, $handler);
        $this->assertNotInstanceOf(ConversesWithUser::class, $handler);
    }

    public function testToolsetIncludesFaqQualifyBookingAndOptOutButNotInventory(): void
    {
        $agentUser = Users::factory()->create(['firstname' => 'Rex', 'lastname' => 'Reception']);
        $agent = $this->makeReceptionistAgent($agentUser, ['role' => []]);

        $handler = new ReceptionistAgent();
        $handler->setConfiguration(agent: $agent, user: auth()->user());

        $method = new ReflectionMethod($handler, 'tools');
        $toolClasses = array_map('get_class', $method->invoke($handler));

        $this->assertContains(FaqLookupTool::class, $toolClasses);
        $this->assertContains(UpdateLeadTool::class, $toolClasses);
        $this->assertContains(StopContactTool::class, $toolClasses);
        $this->assertContains(CalendarEventTool::class, $toolClasses);
        $this->assertNotContains(InventorySearchTool::class, $toolClasses);
    }
}
