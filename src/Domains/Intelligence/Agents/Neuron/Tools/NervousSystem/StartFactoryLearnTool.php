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

#[AgentTool(name: 'Start Factory Learn', category: 'nervous_system')]
class StartFactoryLearnTool extends Tool implements RequiresSystemAgent
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesProjectForTool;
    use StartsFactoryStage;
    use TrackByInputs;

    protected string $name = 'start_factory_learn';

    protected ?string $description = 'Start the Learn & improve stage of an app that already exists: the project\'s Learn '
        . 'agent reads the app\'s product analytics and production errors, reads the earlier improvements\' results, '
        . 'and answers with up to three ranked improvements. When it finishes you are woken: start each improvement '
        . 'with start_factory_ticket (improvements skip the go/no-go gate), one after another, then '
        . 'start_factory_release. Run it on the app\'s schedule (weekly) or when a released improvement is due its '
        . 'reading. Calling it again while one is open returns the one already on the board.';

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
                description: 'Optional focus for this round, e.g. a metric a person asked about or a release whose '
                    . 'reading is due.',
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
            fn (Project $project): FactoryStage => FactoryStage::learn($project, $repository, $notes),
        );
    }
}
