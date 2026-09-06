<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\CustomerSuccess;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\CustomerSuccess\CustomerUpdateAgent;
use ReflectionMethod;
use Tests\TestCase;

final class CustomerUpdateAgentToolsTest extends TestCase
{
    /**
     * The account notes thread is this agent's own grounding source — it reads the window to decide
     * what an account cares about, so it has to be able to write back what it learned.
     */
    public function testExposesTheAccountNoteWriter(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create();

        $handler = new CustomerUpdateAgent();
        $handler->setConfiguration(agent: $agent, user: $user);

        $this->assertContains('add_organization_note', $this->toolNames($handler));
    }

    public function testReturnsNoToolsWhenUnconfigured(): void
    {
        $this->assertSame([], $this->toolNames(new CustomerUpdateAgent()));
    }

    /**
     * @return array<int, string>
     */
    private function toolNames(CustomerUpdateAgent $handler): array
    {
        /** @var array<int, object> $tools */
        $tools = new ReflectionMethod($handler, 'tools')->invoke($handler);

        return array_map(
            static fn (object $tool): string => method_exists($tool, 'getName')
                ? (string) $tool->getName()
                : (string) ($tool->name ?? ''),
            $tools,
        );
    }
}
