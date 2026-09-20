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

#[AgentTool(name: 'Save Browser File', category: 'kernel')]
class SaveBrowserFileTool extends Tool implements HasRunKey, RequiresMcpConnection
{
    use HasKanvasContext;
    use SavesKernelBrowserFiles;
    use TrackByInputs;

    /** $files is a test seam; an agent's toolset is built with the agent alone. */
    public function __construct(
        private readonly ?Agent $agent = null,
        private readonly ?BrowserFiles $files = null,
    ) {
        parent::__construct(
            name: 'save_browser_file',
            description: 'Copy one file out of a Kernel browser session into Kanvas and attach it to a plan. '
                . 'Use it for anything the session produced that matters — a portal export you downloaded, a '
                . 'CSV you wrote. CALL IT BEFORE CLOSING THE SESSION: Kernel deletes the whole filesystem when '
                . 'the session ends and the file is then gone for good. The file goes to a plan, not into this '
                . 'conversation, so it can be handed to another agent or a later run whatever its size.',
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
                description: 'The Kernel browser session the file is in.',
                required: true,
            ),
            new ToolProperty(
                name: 'path',
                type: PropertyType::STRING,
                description: 'Absolute path inside the session, e.g. /home/kernel/Downloads/export.xlsx or '
                    . '/tmp/report.csv. List the directory first if you are unsure of the file name.',
                required: true,
            ),
            new ToolProperty(
                name: 'plan_id',
                type: PropertyType::INTEGER,
                description: 'Optional plan to attach it to. Omit to create one for this session\'s files.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $session_id, string $path, ?int $plan_id = null): array
    {
        $refusal = $this->withoutAgent($this->agent);

        if ($refusal !== null) {
            return $refusal;
        }

        return $this->saveToPlan(
            $this->agent,
            trim($session_id),
            [['path' => trim($path)]],
            $plan_id,
            'Browser session file — ' . basename(trim($path)),
        );
    }
}
