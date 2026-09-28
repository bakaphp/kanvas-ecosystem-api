<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\CustomerSuccess;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\CustomerSuccess\CustomerUpdateAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\ProvisionMyEmailInboxTool;
use Kanvas\NervousSystem\Capability\Models\Tool;
use ReflectionMethod;
use Tests\TestCase;

final class CustomerUpdateAgentToolsTest extends TestCase
{
    protected $connectionsToTransact = [null, 'intelligence'];

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

    /**
     * Regression: tools() overrode the parent without calling mergeRegisteredTools(), so a tool
     * granted in the admin UI never reached the agent — she reported the same four tools forever and
     * nothing surfaced why. The baseline is a floor, not a ceiling.
     */
    public function testGrantedCatalogToolsReachTheAgent(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create();

        $granted = Tool::create([
            'apps_id' => $app->getId(),
            'name' => 'Provision My Email Inbox',
            'description' => 'Fixture grant.',
            'tool_type' => 'system',
            'handler' => ProvisionMyEmailInboxTool::class,
            'frameworks' => ['neuron'],
            'version' => '1.0.0',
            'is_active' => 1,
            'is_deleted' => 0,
        ]);
        $agent->selectedTools()->attach($granted->getId());

        $handler = new CustomerUpdateAgent();
        $handler->setConfiguration(agent: $agent, user: $user);

        $names = $this->toolNames($handler);

        $this->assertContains('provision_my_email_inbox', $names);
        // The lean baseline still stands — merging adds, it does not replace.
        $this->assertContains('add_organization_note', $names);
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
