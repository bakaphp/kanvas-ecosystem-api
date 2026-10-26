<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron;

use Kanvas\Intelligence\Agents\Neuron\Middleware\KanvasToolSearchMiddleware;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\KanvasToolSearchTool;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use Override;
use Tests\Stubs\Intelligence\CapturingNeuronAgentStub;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\Stubs\Intelligence\Tools\CallbackTool;
use Tests\TestCase;

/**
 * Replays the turn that asked for a WhatsApp message on an agent with no such tool: nine searches
 * with different words, because every miss read as "try another word". A miss now lists the whole
 * pool and says it is complete, and the search closes after a few calls.
 */
class ToolSearchMissTest extends TestCase
{
    public function testAMissListsEveryToolAndSaysTheListIsComplete(): void
    {
        $provider = $this->scriptedProvider([
            new ToolCallMessage(null, [ToolCall::make('tool_search', 'c-1', ['query' => 'whatsapp'])]),
            new AssistantMessage('I cannot send WhatsApp messages.'),
        ]);
        $agent = $this->agentWithPool($provider, $this->pool());

        $agent->chat(new UserMessage('send Guy a WhatsApp, say hello'));

        $miss = $provider->toolResults[0];
        $this->assertStringContainsString("No tool matches 'whatsapp'", $miss);
        $this->assertStringContainsString('This list is complete', $miss);
        foreach ($this->pool() as $tool) {
            $this->assertStringContainsString($tool->getName(), $miss, 'Every pooled tool is named on a miss');
        }
        $this->assertStringContainsString('report_capability_gap', $miss);
    }

    public function testAnMcpToolkitCollapsesToOneLineInTheMissList(): void
    {
        $provider = $this->scriptedProvider([
            new ToolCallMessage(null, [ToolCall::make('tool_search', 'c-1', ['query' => 'whatsapp'])]),
            new AssistantMessage('no'),
        ]);
        $pool = $this->pool();
        foreach (['manage_browsers', 'search_docs', 'exec_command'] as $remote) {
            $pool[] = new CallbackTool('kernel__' . $remote, 'Kernel ' . $remote, static fn (): string => 'ok');
        }
        $agent = $this->agentWithPool($provider, $pool);

        $agent->chat(new UserMessage('send Guy a WhatsApp'));

        $miss = $provider->toolResults[0];
        $this->assertStringContainsString('the 9 searchable tools you hold are', $miss);
        $this->assertStringContainsString('the kernel MCP toolkit (3 tools, prefixed kernel__)', $miss);
        $this->assertStringNotContainsString('kernel__manage_browsers', $miss, 'Remote tool names are not a menu to try');
        $this->assertStringContainsString('Do not probe other tools', $miss);
    }

    public function testAHitStillAnswersAsTheStockSearchDoes(): void
    {
        $provider = $this->scriptedProvider([
            new ToolCallMessage(null, [ToolCall::make('tool_search', 'c-1', ['query' => 'invoice'])]),
            new AssistantMessage('ok'),
        ]);
        $agent = $this->agentWithPool($provider, $this->pool());

        $agent->chat(new UserMessage('invoice Guy'));

        $this->assertStringStartsWith('Found 1 tool(s):', $provider->toolResults[0]);
        $this->assertStringNotContainsString('This list is complete', $provider->toolResults[0]);
        $this->assertStringNotContainsString('Showing', $provider->toolResults[0], 'Nothing was left out, so nothing to say');
    }

    /**
     * The KIAS turn: nine kias__ tools in the pool, a search for "kias" shows five, and the model
     * reported those five as every tool it had.
     */
    public function testAHitSaysHowManyMoreMatchedAndNamesThem(): void
    {
        $provider = $this->scriptedProvider([
            new ToolCallMessage(null, [ToolCall::make('tool_search', 'c-1', ['query' => 'kias'])]),
            new AssistantMessage('ok'),
        ]);
        $agent = $this->agentWithPool($provider, $this->kiasPool());

        $agent->chat(new UserMessage('which kias tools do you have?'));

        $hit = $provider->toolResults[0];
        $this->assertStringStartsWith('Found 5 tool(s):', $hit);
        $this->assertStringContainsString('Showing 5 of 9 matches. Also matching, not loaded: ', $hit);
        $this->assertStringContainsString('Search for one by its exact name', $hit);
        preg_match_all('/kias__[a-z_]+/', $hit, $names);
        $this->assertCount(9, array_unique($names[0]), 'Every matching tool is named, loaded or not');
    }

    public function testThePromptNamesThePoolAndSaysASearchIsPartial(): void
    {
        $provider = $this->scriptedProvider([new AssistantMessage('ok')]);
        $agent = $this->agentWithPool($provider, [...$this->pool(), ...$this->kiasPool()]);

        $agent->chat(new UserMessage('hi'));

        $prompt = (string) $provider->instructions;
        $this->assertStringContainsString('Your searchable pool holds 15 tools', $prompt);
        $this->assertStringContainsString('the kias MCP toolkit (9 tools, prefixed kias__)', $prompt);
        $this->assertStringContainsString('create_invoice', $prompt);
        $this->assertStringNotContainsString('kias__create_task', $prompt, 'A toolkit is one line, not a menu');
        $this->assertStringContainsString('at most 5 matches', $prompt);
        $this->assertStringContainsString('until the next user message', $prompt);
        $this->assertStringContainsString('never from what one search returned', $prompt);
    }

    public function testASearchForAToolAlreadyDeclaredPointsAtItInsteadOfTheList(): void
    {
        $provider = $this->scriptedProvider([
            new ToolCallMessage(null, [ToolCall::make('tool_search', 'c-1', ['query' => 'plan'])]),
            new AssistantMessage('ok'),
        ]);
        $agent = $this->agentWithPool($provider, $this->pool());
        $agent->addTool(new CallbackTool('create_plan', 'Create a plan with its tasks.', static fn (): string => 'done'));

        $agent->chat(new UserMessage('make a plan for the launch'));

        $reply = $provider->toolResults[0];
        $this->assertStringContainsString('you already hold create_plan', $reply);
        $this->assertStringContainsString('Call them directly', $reply);
        $this->assertStringNotContainsString('This list is complete', $reply, 'The pool list is noise when the tool is already in hand');
    }

    public function testThePromptSaysDeclaredToolsNeedNoSearch(): void
    {
        $prompt = KanvasToolSearchMiddleware::SYSTEM_PROMPT;

        $this->assertStringContainsString('call them directly', $prompt);
        $this->assertStringContainsString('Never search for a tool you can already call', $prompt);
        $this->assertStringNotContainsString('Always search', $prompt, "Neuron's search-first line is what sent Jessica searching for tools she held");
    }

    public function testTheSearchClosesAfterTheCap(): void
    {
        $script = [];
        foreach (['whatsapp', 'message', 'send', 'chat', 'text', 'notify'] as $i => $word) {
            $script[] = new ToolCallMessage(null, [ToolCall::make('tool_search', 'c-' . $i, ['query' => $word])]);
        }
        $script[] = new AssistantMessage('I cannot.');
        $provider = $this->scriptedProvider($script);
        $agent = $this->agentWithPool($provider, $this->pool());

        $agent->chat(new UserMessage('send Guy a WhatsApp'));

        $this->assertCount(6, $provider->toolResults, 'Every call is answered, none is skipped');
        $this->assertStringContainsString("No tool matches 'chat'", $provider->toolResults[3], 'The fourth search still runs');
        $this->assertStringContainsString('already searched 4 times', $provider->toolResults[4], 'The fifth is refused');
        $this->assertStringContainsString('already searched 5 times', $provider->toolResults[5]);
        $this->assertSame(KanvasToolSearchTool::MAX_SEARCHES_PER_TURN, 4);
    }

    public function testTheCountRestartsOnTheNextUserMessage(): void
    {
        $script = [];
        foreach (['a', 'b', 'c', 'd'] as $i => $word) {
            $script[] = new ToolCallMessage(null, [ToolCall::make('tool_search', 'c-' . $i, ['query' => $word])]);
        }
        $script[] = new AssistantMessage('no');
        $script[] = new ToolCallMessage(null, [ToolCall::make('tool_search', 'c-9', ['query' => 'invoice'])]);
        $script[] = new AssistantMessage('found it');
        $provider = $this->scriptedProvider($script);
        $agent = $this->agentWithPool($provider, $this->pool());

        $agent->chat(new UserMessage('first'));
        $agent->chat(new UserMessage('second'));

        $this->assertStringStartsWith('Found 1 tool(s):', $provider->toolResults[4], 'A new turn starts with a fresh budget');
    }

    /**
     * @param list<ToolInterface> $pool
     */
    private function agentWithPool(FakeNeuronProvider $provider, array $pool): CapturingNeuronAgentStub
    {
        $names = array_map(static fn (ToolInterface $tool): string => $tool->getName(), $pool);

        $agent = new class ($names) extends CapturingNeuronAgentStub {
            /** @param list<string> $names */
            public function __construct(private readonly array $names)
            {
            }

            #[Override]
            protected function toolSearchActive(): bool
            {
                return true;
            }

            #[Override]
            protected function searchableToolNames(): array
            {
                return $this->names;
            }
        };
        $agent->setThreadId('tool-search-miss-' . uniqid());
        $agent->capturedProvider = $provider;
        $agent->addTool($pool);

        return $agent;
    }

    /**
     * @return list<ToolInterface>
     */
    private function pool(): array
    {
        $tools = [];
        foreach ([
            'create_invoice' => 'Create an invoice for a customer.',
            'send_quote' => 'Send a quote to a prospect.',
            'list_orders' => 'List recent orders.',
            'update_order' => 'Update an order.',
            'cancel_order' => 'Cancel an order.',
            'refund_order' => 'Refund an order.',
        ] as $name => $description) {
            $tools[] = new CallbackTool($name, $description, static fn (): string => 'done');
        }

        return $tools;
    }

    /**
     * @return list<ToolInterface>
     */
    private function kiasPool(): array
    {
        $tools = [];
        foreach ([
            'check_processed_message',
            'create_task',
            'get_task',
            'link_task_thread',
            'list_expiring_tasks',
            'list_open_tasks',
            'mark_message_processed',
            'record_task_followup',
            'update_task',
        ] as $name) {
            $tools[] = new CallbackTool('kias__' . $name, 'KIAS: ' . str_replace('_', ' ', $name) . '.', static fn (): string => 'ok');
        }

        return $tools;
    }

    /**
     * @param list<Message> $script
     */
    private function scriptedProvider(array $script): FakeNeuronProvider
    {
        return new class ($script) extends FakeNeuronProvider {
            /** @var list<string> */
            public array $toolResults = [];

            private int $calls = 0;

            /** @param list<Message> $script */
            public function __construct(private readonly array $script)
            {
                parent::__construct();
            }

            #[Override]
            public function chat(Message ...$messages): ProviderResponse
            {
                $last = end($messages);
                if ($last instanceof ToolResultMessage) {
                    foreach ($last->getToolCalls() as $call) {
                        $this->toolResults[] = (string) $call->getResult();
                    }
                }

                return $this->respond($this->script[min($this->calls++, count($this->script) - 1)]);
            }
        };
    }
}
