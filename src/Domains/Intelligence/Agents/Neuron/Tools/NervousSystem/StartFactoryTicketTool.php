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

#[AgentTool(name: 'Start Factory Ticket', category: 'nervous_system')]
class StartFactoryTicketTool extends Tool implements RequiresSystemAgent
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesProjectForTool;
    use StartsFactoryStage;
    use TrackByInputs;

    protected string $name = 'start_factory_ticket';

    protected ?string $description = 'Start one ticket of an app\'s approved technical plan on the factory Mac: the '
        . 'designer designs it on the ticket\'s branch, then the builder adds the logic and opens the pull request into '
        . 'develop; a ticket with nothing on screen (has_screens=false) goes to the builder alone. The steps are '
        . 'written and assigned for you; use this instead of writing ticket tasks yourself. '
        . 'Start tickets in the plan\'s order, and a ticket that depends on another only after that one\'s pull '
        . 'request is merged. Calling it again for the same ticket returns the one already on the board.';

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
                name: 'ticket_number',
                type: PropertyType::INTEGER,
                description: 'The ticket\'s number in the approved technical plan.',
                required: true,
            ),
            new ToolProperty(
                name: 'short_name',
                type: PropertyType::STRING,
                description: 'A few words naming the ticket, e.g. "record list screen". It names the branch.',
                required: true,
            ),
            new ToolProperty(
                name: 'ticket',
                type: PropertyType::STRING,
                description: 'The ticket\'s full text from the technical plan: user story, acceptance criteria, '
                    . 'template constraints, the query clause and the component stock audit.',
                required: true,
            ),
            new ToolProperty(
                name: 'has_screens',
                type: PropertyType::BOOLEAN,
                description: 'False for a ticket with nothing on screen (interfaces, services, hooks, navigation '
                    . 'config): it skips the design step and goes straight to the builder. Default true.',
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
        int $ticket_number,
        string $short_name,
        string $ticket,
        ?bool $has_screens = null
    ): array {
        return $this->startStage(
            $project_id,
            fn (Project $project): FactoryStage => FactoryStage::ticket(
                $project,
                $repository,
                $ticket_number,
                $short_name,
                $ticket,
                $has_screens ?? true
            ),
        );
    }
}
