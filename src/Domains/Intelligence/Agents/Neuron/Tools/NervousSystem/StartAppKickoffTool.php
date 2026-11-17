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

#[AgentTool(name: 'Start App Kickoff', category: 'nervous_system')]
class StartAppKickoffTool extends Tool implements RequiresSystemAgent
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesProjectForTool;
    use StartsFactoryStage;
    use TrackByInputs;

    protected string $name = 'start_app_kickoff';

    protected ?string $description = 'Start the kickoff of a new mobile app on the factory Mac, once a person has approved '
        . 'the technical plan and confirmed the repository name, display name and bundle id. Creates the kickoff plan '
        . 'with both steps already written and assigned: the app is created from the template, then given its '
        . 'identity, landed on its factory/integration branch. When it finishes, start the first ticket without '
        . 'asking anyone. Use this instead of writing kickoff tasks yourself. Calling it again for the same app '
        . 'returns the kickoff already on the board.';

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
                description: 'The new app\'s repository name, exactly as the person confirmed it, e.g. example-app.',
                required: true,
            ),
            new ToolProperty(
                name: 'display_name',
                type: PropertyType::STRING,
                description: 'The app\'s display name, e.g. Example App.',
                required: true,
            ),
            new ToolProperty(
                name: 'bundle_id',
                type: PropertyType::STRING,
                description: 'The bundle id in reverse-DNS, e.g. com.company.app.',
                required: true,
            ),
            new ToolProperty(
                name: 'notes',
                type: PropertyType::STRING,
                description: 'Optional: brand colours, fonts and the MVP feature list from the approved plan.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $project_id,
        string $repository,
        string $display_name,
        string $bundle_id,
        ?string $notes = null
    ): array {
        return $this->startStage(
            $project_id,
            fn (Project $project): FactoryStage => FactoryStage::kickoff(
                $project,
                $repository,
                $display_name,
                $bundle_id,
                $notes
            ),
        );
    }
}
