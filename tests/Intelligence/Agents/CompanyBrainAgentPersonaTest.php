<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithCustomer;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithUser;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Company\CompanyBrainAgent;
use ReflectionMethod;
use Tests\TestCase;

class CompanyBrainAgentPersonaTest extends TestCase
{
    private function makeBrainAgent(): Agent
    {
        $app = app(Apps::class);
        $user = auth()->user();

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create(['user_id' => $user->getId()]);
    }

    public function testInstructionsCarryTheBrainDoctrineAndTheAgentIdentity(): void
    {
        $agent = $this->makeBrainAgent();

        $handler = new CompanyBrainAgent();
        $handler->setConfiguration(agent: $agent, user: auth()->user());

        $instructions = $handler->instructions();

        $this->assertStringContainsString('company brain', strtolower($instructions));
        $this->assertStringContainsString('Look, don\'t guess.', $instructions);
        $this->assertStringContainsString('Find the signal', $instructions);
        // The five-step lens is the brain's method for turning cross-domain data into direction.
        $this->assertStringContainsString('five-step lens', $instructions);
        // The SystemUserAgent identity block still grounds it as a real Kanvas user.
        $this->assertStringContainsString('You ARE a Kanvas user', $instructions);
    }

    public function testExposesReadBundleButNoCustomerFacingOrBulkWriteTools(): void
    {
        $agent = $this->makeBrainAgent();

        $handler = new CompanyBrainAgent();
        $handler->setConfiguration(agent: $agent, user: auth()->user());

        /** @var array<int, object> $tools */
        $tools = new ReflectionMethod($handler, 'tools')->invoke($handler);
        $names = array_map(
            static fn (object $tool): string => method_exists($tool, 'getName') ? (string) $tool->getName() : (string) ($tool->name ?? ''),
            $tools,
        );

        // Read-broad: the cross-domain analytics bundle plus its inherited ledger memory.
        foreach ([
            'get_sales_summary',
            'get_lead_analytics',
            'get_deal_analytics',
            'get_customer_stats',
            'get_company_breakdown',
            'search_leads',
            'find_leads_by_traits',
            'list_stale_leads',
            'get_message_usage_report',
            'list_projects',
            'get_project_analytics',
            'read_my_ledger',
            'remember',
        ] as $expected) {
            $this->assertContains($expected, $names, "Brain should expose {$expected}");
        }

        // Write-narrow: no customer-facing, bulk, or ownership-mutating tool is hardcoded on the type.
        // (remember is the one allowed write — it only touches the agent's own memory.)
        foreach (['send_sms', 'send_email', 'send_batch_message', 'reassign_lead_owner'] as $forbidden) {
            $this->assertNotContains($forbidden, $names, "Brain must NOT hardcode {$forbidden}");
        }
    }

    public function testReturnsNoBundleWhenUnconfigured(): void
    {
        $this->assertSame([], new ReflectionMethod(new CompanyBrainAgent(), 'tools')->invoke(new CompanyBrainAgent()));
    }

    public function testCompanyBrainIsInternalNotCustomerFacing(): void
    {
        $handler = new CompanyBrainAgent();

        $this->assertInstanceOf(ConversesWithUser::class, $handler);
        $this->assertNotInstanceOf(ConversesWithCustomer::class, $handler);
    }
}
