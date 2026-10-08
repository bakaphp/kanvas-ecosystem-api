<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Browser;

use Kanvas\Intelligence\Agents\Neuron\Browser\BrowserSession;
use Kanvas\Intelligence\Agents\Neuron\Browser\BrowserTestAgent;
use Kanvas\Intelligence\Agents\Neuron\Browser\BrowserToolkit;
use Kanvas\Intelligence\Agents\Neuron\Browser\BrowserUrlValidator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Tools\ToolCall;
use Override;
use RuntimeException;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\TestCase;

class BrowserNeuronIntegrationTest extends TestCase
{
    public function testBrowserToolsAllowMultiStoreResearchFlows(): void
    {
        $browser = new BrowserSession('ws://browser:3000/playwright', new BrowserUrlValidator());
        $tools = new BrowserToolkit($browser)->provide();

        foreach ($tools as $tool) {
            $this->assertSame(100, $tool->getMaxRuns());
        }
    }

    public function testNeuronExecutesTheBrowserToolkitFlow(): void
    {
        $endpoint = (string) getenv('BROWSER_WS_ENDPOINT');
        if ($endpoint === '') {
            $this->markTestSkipped('BROWSER_WS_ENDPOINT is not configured.');
        }

        $browser = new BrowserSession($endpoint, new BrowserUrlValidator(['browser-fixture']));
        $provider = new ScriptedBrowserProvider();
        $agent = new BrowserTestAgent($provider, new BrowserToolkit($browser));
        $agent->setThreadId('browser-toolkit-' . uniqid());

        try {
            $state = $agent->chat(new UserMessage(
                'Navigate to the test page. Find the search field, search for "hello world", '
                . 'submit it, and tell me what the page displays. Use the browser tools.',
            ));

            $answer = (string) $state->getMessage()->getContent();
            $this->assertStringContainsString('Search result: hello world', $answer);
            $this->assertSame([
                'browser_navigate',
                'browser_snapshot',
                'browser_type',
                'browser_press',
                'browser_snapshot',
            ], $provider->calls);
        } finally {
            $agent->close();
        }
    }
}

/**
 * Scripts the model side of a five-step browse. Each turn answers with a ToolCall by name and reads the
 * previous call's result from the ToolResultMessage the agent hands back, exactly as a real model would.
 */
final class ScriptedBrowserProvider extends FakeNeuronProvider
{
    /** @var list<string> */
    public array $calls = [];
    private int $turn = 0;
    /** @var array<string, mixed> */
    private array $lastSnapshot = [];

    #[Override]
    public function chat(Message ...$messages): ProviderResponse
    {
        $this->turn++;
        $this->rememberSnapshot($messages);

        return $this->respond(match ($this->turn) {
            1 => $this->call('browser_navigate', ['url' => 'http://browser-fixture/']),
            2 => $this->call('browser_snapshot'),
            3 => $this->call('browser_type', [
                'element_id' => $this->searchInputId(),
                'text' => 'hello world',
            ]),
            4 => $this->call('browser_press', [
                'element_id' => $this->searchInputId(),
                'key' => 'Enter',
            ]),
            5 => $this->call('browser_snapshot'),
            default => new AssistantMessage((string) ($this->lastSnapshot['text'] ?? '')),
        });
    }

    #[Override]
    public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse
    {
        return $this->chat(...(is_array($messages) ? $messages : [$messages]));
    }

    /** @param array<string, mixed> $inputs */
    private function call(string $name, array $inputs = []): ToolCallMessage
    {
        $this->calls[] = $name;

        return new ToolCallMessage(null, [ToolCall::make($name, 'browser-test-' . $this->turn, $inputs)]);
    }

    /** @param list<Message> $messages */
    private function rememberSnapshot(array $messages): void
    {
        $last = end($messages);
        if (! $last instanceof ToolResultMessage) {
            return;
        }

        foreach ($last->getToolCalls() as $call) {
            if ($call->getName() === 'browser_snapshot' && $call->hasResult()) {
                $this->lastSnapshot = json_decode((string) $call->getResult(), true, flags: JSON_THROW_ON_ERROR);
            }
        }
    }

    private function searchInputId(): int
    {
        foreach ($this->lastSnapshot['elements'] ?? [] as $element) {
            if (($element['role'] ?? null) === 'textbox') {
                return (int) $element['id'];
            }
        }

        throw new RuntimeException('Search input was not present in the browser snapshot.');
    }
}
