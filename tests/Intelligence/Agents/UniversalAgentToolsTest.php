<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Laravel\Inventory\AgentInventoryAssistance;
use Kanvas\Intelligence\Agents\Laravel\KanvasGenericLaravelAgent;
use Kanvas\Intelligence\Agents\Laravel\Tools\Common\CurrentTimeTool as LaravelCurrentTimeTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Accounting\CFOAgent;
use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Common\CurrentTimeTool as NeuronCurrentTimeTool;
use Kanvas\NervousSystem\Capability\Actions\CreateToolAction;
use Kanvas\NervousSystem\Capability\Actions\GrantToolToAgentAction;
use Kanvas\NervousSystem\Capability\DataTransferObject\Tool as ToolData;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use NeuronAI\Tools\ToolInterface;
use Tests\TestCase;

/**
 * Souls tell the model to call get_current_time, and Neuron kills the turn with a
 * ProviderException when the model names a tool the provider wasn't given
 * (Sentry KANVAS-ECOSYSTEM-600). Time must therefore reach every agent regardless of
 * which tools an operator selected in the registry.
 */
final class UniversalAgentToolsTest extends TestCase
{
    use DatabaseTransactions;

    public function testGenericNeuronAgentWithoutGrantedToolsStillExposesCurrentTime(): void
    {
        $handler = new KanvasGenericNeuronAgent();
        $handler->setConfiguration($this->makeAgent('neuron'), user: auth()->user());

        $this->assertContains('get_current_time', $this->neuronToolNames($handler->getTools()));
    }

    public function testSystemUserAgentWithoutGrantedToolsStillExposesCurrentTime(): void
    {
        $handler = new SystemUserAgent();
        $handler->setConfiguration($this->makeAgent('neuron'), user: auth()->user());

        $this->assertContains('get_current_time', $this->neuronToolNames($handler->getTools()));
    }

    public function testHardcodedToolAgentStillExposesCurrentTime(): void
    {
        $handler = new CFOAgent();
        $handler->setConfiguration($this->makeAgent('neuron'), user: auth()->user());

        $this->assertContains('get_current_time', $this->neuronToolNames($handler->getTools()));
    }

    public function testGrantingCurrentTimeDoesNotRegisterItTwice(): void
    {
        $agent = $this->makeAgent('neuron');

        $tool = new CreateToolAction(new ToolData(
            app: app(Apps::class),
            name: 'current-time-' . uniqid(),
            frameworks: ['neuron'],
            toolType: ToolTypeEnum::CUSTOM,
            handler: NeuronCurrentTimeTool::class,
        ))->execute();

        new GrantToolToAgentAction($agent, $tool)->execute();

        $handler = new KanvasGenericNeuronAgent();
        $handler->setConfiguration($agent, user: auth()->user());

        $names = $this->neuronToolNames($handler->getTools());

        $this->assertSame(
            1,
            count(array_filter($names, fn (string $name): bool => $name === 'get_current_time')),
        );
    }

    /**
     * Asserts on tools() — not agentTools() — because that is the list the runtime hands the
     * provider, and it is where the universal injection lives so that EVERY Laravel agent gets
     * time, not just the registry-driven generic one.
     */
    public function testGenericLaravelAgentWithoutGrantedToolsStillExposesCurrentTime(): void
    {
        $handler = new KanvasGenericLaravelAgent();
        $handler->setConfiguration($this->makeAgent('laravel'));

        $tools = iterator_to_array($handler->tools(), false);

        $this->assertNotEmpty(array_filter(
            $tools,
            fn (object $tool): bool => $tool instanceof LaravelCurrentTimeTool,
        ));
    }

    public function testHardcodedToolLaravelAgentStillExposesCurrentTime(): void
    {
        $handler = new AgentInventoryAssistance();
        $handler->setConfiguration($this->makeAgent('laravel'));

        $tools = iterator_to_array($handler->tools(), false);

        $this->assertNotEmpty(array_filter(
            $tools,
            fn (object $tool): bool => $tool instanceof LaravelCurrentTimeTool,
        ));
    }

    private function makeAgent(string $provider): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $type = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['provider' => $provider]);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $type->getId()]);
    }

    /**
     * @param array<int, object> $tools
     * @return list<string>
     */
    private function neuronToolNames(array $tools): array
    {
        return array_values(array_map(
            fn (ToolInterface $tool): string => $tool->getName(),
            array_filter($tools, fn (object $tool): bool => $tool instanceof ToolInterface),
        ));
    }
}
