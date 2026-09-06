<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesManagerAgent;
use ReflectionMethod;
use Tests\TestCase;

class SalesManagerAgentTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function toolNames(SalesManagerAgent $handler): array
    {
        /** @var array<int, object> $tools */
        $tools = new ReflectionMethod($handler, 'tools')->invoke($handler);

        return array_map(
            static fn (object $tool): string => method_exists($tool, 'getName') ? (string) $tool->getName() : (string) ($tool->name ?? ''),
            $tools,
        );
    }

    public function testExposesThePollyToolset(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create();

        $handler = new SalesManagerAgent();
        $handler->setConfiguration(agent: $agent, user: $user);

        $names = $this->toolNames($handler);

        foreach ([
            'get_message_usage_report',
            'get_lead_analytics',
            'get_sales_summary',
            'search_leads',
            // Four of Polly's own write tools tell the model to source lead_id from get_lead_ref;
            // without it the model calls a tool it doesn't have (KANVAS-ECOSYSTEM-675).
            'get_lead_ref',
            'find_leads_by_traits',
            'get_batch_history',
            'reassign_lead_owner',
            'send_batch_message',
            'add_lead_note',
            // The person-note chain has to be granted whole: add_person_note points the model at
            // find_person, which in turn points at get_person and find_people_bulk. A missing name
            // kills the turn in findTool() (KANVAS-ECOSYSTEM-675), and dropping find_people_bulk
            // specifically sends a pasted list of names down the per-row path that exhausts the run
            // budget (KANVAS-ECOSYSTEM-64Q).
            'find_person',
            'find_people_bulk',
            'get_person',
            'add_person_note',
            'add_organization_note',
            'upload_file_to_lead',
            'upload_file_to_message',
        ] as $expected) {
            $this->assertContains($expected, $names, "Polly should expose {$expected}");
        }
    }

    public function testReturnsNoToolsWhenUnconfigured(): void
    {
        $this->assertSame([], $this->toolNames(new SalesManagerAgent()));
    }
}
