<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\FactoryStage;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\StartsFactoryStage;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesProjectForTool;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\NervousSystem\Project\Models\Project;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Start Factory Release', category: 'nervous_system')]
class StartFactoryReleaseTool extends Tool implements RequiresSystemAgent
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesProjectForTool;
    use StartsFactoryStage;
    use TrackByInputs;

    protected string $name = 'start_factory_release';

    protected ?string $description = 'Open the one pull request of an app\'s stage on the factory Mac: everything its '
        . 'tickets landed on the factory/integration branch goes into develop, with a dated changelog and QA on both '
        . 'platforms. Call it once, when the last ticket of the approved plan has finished, without asking anyone: '
        . 'the person reviews and merges this pull request, not each ticket. Calling it again the same day returns '
        . 'the release already on the board.';

    public function __construct(
        private readonly ?Session $session = null,
    ) {
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'project_id',
                type: PropertyType::INTEGER,
                description: 'The factory project.',
                required: true,
            ),
            new ToolProperty(
                name: 'repository',
                type: PropertyType::STRING,
                description: 'The app\'s repository name, e.g. example-app.',
                required: true,
            ),
            new ToolProperty(
                name: 'notes',
                type: PropertyType::STRING,
                description: 'Optional: the tickets this release closes and anything the pull request must mention.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $project_id, string $repository, ?string $notes = null): array
    {
        return $this->startStage(
            $project_id,
            fn (Project $project): FactoryStage => FactoryStage::release($project, $repository, $notes),
        );
    }
}
