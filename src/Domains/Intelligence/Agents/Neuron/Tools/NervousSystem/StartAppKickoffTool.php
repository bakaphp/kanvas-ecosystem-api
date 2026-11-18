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
        . 'with its steps already written and assigned: the app is created from the template, given its identity '
        . 'and, with brief_url and plan_url, designed ticket by ticket from the approved plan (brand and theme from '
        . 'the brief, a mockup of every screen each ticket builds, which that ticket is then built and checked '
        . 'against), landed on its factory/integration branch. When it finishes, start the first ticket without '
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
            new ToolProperty(
                name: 'brief_url',
                type: PropertyType::STRING,
                description: 'The URL of the approved Business Brief file, exactly as list_task_files shows it on the '
                    . 'research task. Pass it with plan_url so the kickoff designs the app.',
                required: false,
            ),
            new ToolProperty(
                name: 'plan_url',
                type: PropertyType::STRING,
                description: 'The URL of the approved technical plan file (the tickets), exactly as list_task_files '
                    . 'shows it on the planning task. The design draws exactly the screens its tickets build.',
                required: false,
            ),
            new ToolProperty(
                name: 'design_files',
                type: PropertyType::STRING,
                description: 'Optional: the URLs of design files the person gave (mockups, brand, palette), one per '
                    . 'line. The design matches them. Needs brief_url and plan_url.',
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
        ?string $notes = null,
        ?string $brief_url = null,
        ?string $plan_url = null,
        ?string $design_files = null
    ): array {
        $designFiles = array_values(array_filter(
            array_map(trim(...), preg_split('/\R/', (string) $design_files) ?: []),
            fn (string $line): bool => $line !== '',
        ));

        return $this->startStage(
            $project_id,
            fn (Project $project): FactoryStage => FactoryStage::kickoff(
                $project,
                $repository,
                $display_name,
                $bundle_id,
                notes: $notes,
                briefUrl: $brief_url,
                planUrl: $plan_url,
                designFiles: $designFiles,
            ),
        );
    }
}
