<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Browser;

use Generator;
use Kanvas\Intelligence\Agents\Neuron\Browser\BrowserSession;
use Kanvas\Intelligence\Agents\Neuron\Browser\BrowserTestAgent;
use Kanvas\Intelligence\Agents\Neuron\Browser\BrowserToolkit;
use Kanvas\Intelligence\Agents\Neuron\Browser\BrowserUrlValidator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Tools\ToolInterface;
use RuntimeException;
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

        try {
            $state = $agent->chat(new UserMessage(
                'Navigate to the test page. Find the search field, search for "hello world", '
                . 'submit it, and tell me what the page displays. Use the browser tools.',
            ))->run();

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

final class ScriptedBrowserProvider implements AIProviderInterface
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];
    /** @var list<string> */
    public array $calls = [];
    private int $turn = 0;

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        return $this;
    }

    public function setTools(array $tools): AIProviderInterface
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->getName()] = $tool;
        }

        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        return new class () implements MessageMapperInterface {
            public function map(array $messages): array
            {
                return [];
            }
        };
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        return new class () implements ToolMapperInterface {
            public function map(array $tools): array
            {
                return [];
            }
        };
    }

    public function chat(Message ...$messages): Message
    {
        $this->turn++;

        return match ($this->turn) {
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
            default => new AssistantMessage($this->visibleResult()),
        };
    }

    public function stream(Message ...$messages): Generator
    {
        $message = $this->chat(...$messages);
        yield $message;

        return $message;
    }

    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        return $this->chat(...(is_array($messages) ? $messages : [$messages]));
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        return $this;
    }

    private function call(string $name, array $inputs = []): ToolCallMessage
    {
        $tool = $this->tools[$name];
        $tool->setInputs($inputs)->setCallId('browser-test-' . $this->turn);
        $this->calls[] = $name;

        return new ToolCallMessage(null, [$tool]);
    }

    private function searchInputId(): int
    {
        $snapshot = json_decode($this->tools['browser_snapshot']->getResult(), true, flags: JSON_THROW_ON_ERROR);
        foreach ($snapshot['elements'] ?? [] as $element) {
            if (($element['role'] ?? null) === 'textbox') {
                return (int) $element['id'];
            }
        }

        throw new RuntimeException('Search input was not present in the browser snapshot.');
    }

    private function visibleResult(): string
    {
        $snapshot = json_decode($this->tools['browser_snapshot']->getResult(), true, flags: JSON_THROW_ON_ERROR);

        return (string) ($snapshot['text'] ?? '');
    }
}
