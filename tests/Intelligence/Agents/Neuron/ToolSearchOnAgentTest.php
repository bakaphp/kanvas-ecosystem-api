<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Tools\ProviderTool;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use Override;
use Tests\Stubs\Intelligence\CapturingNeuronAgentStub;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\Stubs\Intelligence\Tools\CallbackTool;
use Tests\TestCase;

/**
 * Granted tools leave the prompt and come back through `tool_search`: the first round carries the
 * identity set plus the search tool, a found tool is callable on the next round, a pool tool called
 * by name without a search is loaded on the spot, and a small pool is not worth the detour.
 */
class ToolSearchOnAgentTest extends TestCase
{
    public function testAFoundToolIsCallableOnTheNextRoundAndTheFirstRoundCarriesOnlyTheCore(): void
    {
        $ran = [];
        $provider = $this->scriptedProvider([
            new ToolCallMessage(null, [ToolCall::make('tool_search', 'c-1', ['query' => 'invoice'])]),
            new ToolCallMessage(null, [ToolCall::make('create_invoice', 'c-2', ['amount' => 5])]),
            new AssistantMessage('Invoice created.'),
        ]);
        $agent = $this->agentWithPool($provider, $this->pool($ran));

        $reply = $agent->chat(new UserMessage('Create an invoice for 5'))->getMessage();

        $this->assertSame('Invoice created.', $reply?->getContent());
        $this->assertSame(['create_invoice'], $ran, 'The found tool ran');

        $firstRound = $provider->toolSets[0];
        $this->assertContains('tool_search', $firstRound);
        $this->assertContains('get_current_time', $firstRound, 'The identity set stays');
        $this->assertNotContains('create_invoice', $firstRound, 'A pooled tool is not in the first round');
        $this->assertContains('create_invoice', $provider->toolSets[1], 'Found by the search, loaded for the next round');
        $this->assertNotContains('refund_order', $provider->toolSets[1], 'Only what the search found');
    }

    public function testAPoolToolCalledByNameWithoutASearchIsLoadedAndRuns(): void
    {
        $ran = [];
        $provider = $this->scriptedProvider([
            new ToolCallMessage(null, [ToolCall::make('create_invoice', 'c-1', ['amount' => 5])]),
            new AssistantMessage('Invoice created.'),
        ]);
        $agent = $this->agentWithPool($provider, $this->pool($ran));

        $agent->chat(new UserMessage('Create an invoice for 5'));

        $this->assertSame(['create_invoice'], $ran, 'A pooled tool named outright is a correct call, not a hallucination');
        $this->assertSame('done', (string) $provider->lastToolResult?->getToolCalls()[0]->getResult());
        $this->assertContains('create_invoice', $provider->toolSets[1], 'It stays loaded for the rest of the turn');
    }

    public function testASmallPoolIsNotWorthTheDetour(): void
    {
        $ran = [];
        $provider = $this->scriptedProvider([new AssistantMessage('ok')]);
        $agent = $this->agentWithPool($provider, array_slice($this->pool($ran), 0, 3));

        $agent->chat(new UserMessage('hi'));

        $this->assertNotContains('tool_search', $provider->toolSets[0]);
        $this->assertContains('create_invoice', $provider->toolSets[0], 'Three tools ride along as before');
    }

    public function testAProviderToolRidesAlongUnsplit(): void
    {
        $ran = [];
        $provider = $this->scriptedProvider([new AssistantMessage('ok')]);
        $agent = $this->agentWithPool($provider, $this->pool($ran));
        $agent->addTool(ProviderTool::make('web_search_20250305', 'web_search'));

        $agent->chat(new UserMessage('hi'));

        $this->assertContains('tool_search', $provider->toolSets[0]);
        $this->assertContains('web_search', $provider->toolSets[0], 'A provider-native tool has no schema to hide and no pool to join');
    }

    /**
     * The default policy: pooling catalog grants costs a search round on every ordinary turn, so only
     * MCP toolkits are pooled unless an agent holds an unusual number of grants.
     */
    public function testCatalogGrantsStayInThePromptAndOnlyMcpToolsArePooledByDefault(): void
    {
        $ran = [];
        $catalog = $this->pool($ran);
        $mcp = [];
        foreach (['browsers', 'repl', 'curl', 'profiles', 'proxies', 'vaults'] as $remote) {
            $mcp[] = new CallbackTool('kernel__' . $remote, 'Kernel ' . $remote, static fn (): string => 'ok');
        }
        $provider = $this->scriptedProvider([new AssistantMessage('ok')]);
        $agent = $this->agentWithRegistry($provider, catalog: $catalog, mcp: $mcp);

        $agent->chat(new UserMessage('hi'));

        $this->assertContains('create_invoice', $provider->toolSets[0], 'Seven catalog grants ride along');
        $this->assertNotContains('kernel__browsers', $provider->toolSets[0], 'The toolkit is what the search hides');
        $this->assertContains('tool_search', $provider->toolSets[0]);
    }

    public function testAnUnusualNumberOfCatalogGrantsIsPooledToo(): void
    {
        $catalog = [];
        for ($i = 0; $i < 30; $i++) {
            $catalog[] = new CallbackTool("grant_{$i}", "Catalog grant {$i}.", static fn (): string => 'ok');
        }
        $provider = $this->scriptedProvider([new AssistantMessage('ok')]);
        $agent = $this->agentWithRegistry($provider, catalog: $catalog, mcp: []);

        $agent->chat(new UserMessage('hi'));

        $this->assertNotContains('grant_0', $provider->toolSets[0]);
        $this->assertContains('tool_search', $provider->toolSets[0]);
    }

    /**
     * An agent whose registry recorded the given grants, with the default pooling policy.
     *
     * @param list<ToolInterface> $catalog
     * @param list<ToolInterface> $mcp
     */
    private function agentWithRegistry(FakeNeuronProvider $provider, array $catalog, array $mcp): CapturingNeuronAgentStub
    {
        $names = static fn (array $tools): array => array_map(static fn (ToolInterface $tool): string => $tool->getName(), $tools);

        $agent = new class ($names($catalog), $names($mcp)) extends CapturingNeuronAgentStub {
            /**
             * @param list<string> $catalog
             * @param list<string> $mcp
             */
            public function __construct(array $catalog, array $mcp)
            {
                $this->catalogToolNames = $catalog;
                $this->mcpToolNames = $mcp;
            }

            #[Override]
            protected function toolSearchActive(): bool
            {
                return true;
            }
        };
        $agent->setThreadId('tool-search-registry-' . uniqid());
        $agent->capturedProvider = $provider;
        $agent->addTool([...$catalog, ...$mcp]);

        return $agent;
    }

    public function testTheSwitchOffSendsEveryToolOnEveryRound(): void
    {
        $ran = [];
        $provider = $this->scriptedProvider([new AssistantMessage('ok')]);
        $agent = $this->agentWithPool($provider, $this->pool($ran), active: false);

        $agent->chat(new UserMessage('hi'));

        $this->assertNotContains('tool_search', $provider->toolSets[0]);
        $this->assertContains('refund_order', $provider->toolSets[0]);
    }

    /**
     * @param list<ToolInterface> $pool
     */
    private function agentWithPool(FakeNeuronProvider $provider, array $pool, bool $active = true): CapturingNeuronAgentStub
    {
        $names = array_map(static fn (ToolInterface $tool): string => $tool->getName(), $pool);

        $agent = new class ($names, $active) extends CapturingNeuronAgentStub {
            /** @param list<string> $names */
            public function __construct(private readonly array $names, private readonly bool $active)
            {
            }

            #[Override]
            protected function toolSearchActive(): bool
            {
                return $this->active;
            }

            #[Override]
            protected function searchableToolNames(): array
            {
                return $this->names;
            }
        };
        $agent->setThreadId('tool-search-' . uniqid());
        $agent->capturedProvider = $provider;

        foreach ($pool as $tool) {
            $agent->addTool($tool);
        }

        return $agent;
    }

    /**
     * @param list<string> $ran
     * @return list<ToolInterface>
     */
    private function pool(array &$ran): array
    {
        $tools = [];
        foreach ([
            'create_invoice' => 'Create an invoice for a customer.',
            'send_quote' => 'Send a quote to a prospect.',
            'list_orders' => 'List recent orders.',
            'update_order' => 'Update an order.',
            'cancel_order' => 'Cancel an order.',
            'refund_order' => 'Refund an order.',
            'export_report' => 'Export a sales report.',
        ] as $name => $description) {
            $tools[] = new CallbackTool($name, $description, function () use (&$ran, $name): string {
                $ran[] = $name;

                return 'done';
            });
        }

        return $tools;
    }

    /**
     * @param list<Message> $script
     */
    private function scriptedProvider(array $script): FakeNeuronProvider
    {
        return new class ($script) extends FakeNeuronProvider {
            /** @var list<list<string>> */
            public array $toolSets = [];

            public ?ToolResultMessage $lastToolResult = null;

            private int $calls = 0;

            /** @param list<Message> $script */
            public function __construct(private readonly array $script)
            {
                parent::__construct();
            }

            #[Override]
            public function setTools(array $tools): AIProviderInterface
            {
                $this->toolSets[] = array_map(static fn (ToolInterface|ProviderToolInterface $tool): ?string => $tool->getName(), $tools);

                return $this;
            }

            #[Override]
            public function chat(Message ...$messages): ProviderResponse
            {
                $last = end($messages);
                if ($last instanceof ToolResultMessage) {
                    $this->lastToolResult = $last;
                }

                return $this->respond($this->script[min($this->calls++, count($this->script) - 1)]);
            }
        };
    }
}
