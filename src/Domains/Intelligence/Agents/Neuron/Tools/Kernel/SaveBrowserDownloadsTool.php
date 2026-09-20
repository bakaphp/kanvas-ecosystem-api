<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Kernel;

use Kanvas\Connectors\Kernel\Services\BrowserFiles;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresMcpConnection;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Save Browser Downloads', category: 'kernel')]
class SaveBrowserDownloadsTool extends Tool implements HasRunKey, RequiresMcpConnection
{
    use HasKanvasContext;
    use SavesKernelBrowserFiles;
    use TrackByInputs;

    /**
     * $files is a test seam; an agent's toolset is built with the agent alone.
     */
    public function __construct(
        private readonly ?Agent $agent = null,
        private readonly ?BrowserFiles $files = null,
    ) {
        parent::__construct(
            name: 'save_browser_downloads',
            description: 'Copy everything a Kernel browser session produced in the last few hours — whatever it '
                . 'downloaded plus files it wrote to /tmp — into Kanvas, attached to one plan. Use this after a '
                . 'portal export rather than save_browser_file when you do not know the file name the site '
                . 'chose (they are often random). CALL IT BEFORE CLOSING THE SESSION: Kernel deletes the '
                . 'filesystem when the session ends. Returns what was saved and what was skipped, and puts '
                . 'nothing into this conversation.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'session_id',
                type: PropertyType::STRING,
                description: 'The Kernel browser session to collect from.',
                required: true,
            ),
            new ToolProperty(
                name: 'name_contains',
                type: PropertyType::STRING,
                description: 'Optional filter, e.g. "outbound" or ".xlsx", when the session holds more than the '
                    . 'run\'s own files.',
                required: false,
            ),
            new ToolProperty(
                name: 'plan_id',
                type: PropertyType::INTEGER,
                description: 'Optional plan to attach them to. Omit to create one.',
                required: false,
            ),
        ];
    }

    #[Override]
    public function requiredMcpServer(): string
    {
        return BrowserFiles::SERVER;
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $session_id, ?string $name_contains = null, ?int $plan_id = null): array
    {
        if ($this->agent === null) {
            return ['status' => 'error', 'message' => 'No agent is in scope, so the files cannot be saved.'];
        }

        $sessionId = trim($session_id);
        $filter = trim((string) $name_contains);
        $candidates = $this->browserFiles($this->agent)->recent($sessionId);

        $wanted = array_values(array_filter(
            $candidates,
            static fn (array $file): bool => $filter === '' || str_contains(strtolower($file['path']), strtolower($filter))
        ));

        if ($wanted === []) {
            return [
                'status' => 'error',
                'saved' => [],
                'message' => $candidates === []
                    ? 'The session has no recent files. If a download just ran, it may have been blocked — set '
                        . 'the browser\'s download behaviour and try the export again.'
                    : 'No file matched that filter. Drop name_contains to see everything the session produced.',
            ];
        }

        return $this->saveToPlan($this->agent, $sessionId, $wanted, $plan_id, 'Browser session downloads');
    }
}
