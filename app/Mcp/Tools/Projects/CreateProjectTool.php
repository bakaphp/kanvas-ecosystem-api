<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Projects;

use App\GraphQL\Concerns\ActingContext;
use App\Mcp\Tools\Concerns\PresentsProjectBoard;
use App\Mcp\Tools\KanvasTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Enums\ProjectStatusEnum;
use Laravel\Mcp\Request;
use Override;

class CreateProjectTool extends KanvasTool
{
    use PresentsProjectBoard;

    protected string $name = 'create_project';

    protected string $description = 'Create an AI project. Every project is run by a project-manager agent, so agent_id '
        . 'is required — find one with list_agents. The board starts with the default columns '
        . '(TODO, IN PROGRESS, BLOCKED, DONE).';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Project name.')->required(),
            'agent_id' => $schema->integer()
                ->description('The agent that manages the project. Get it from list_agents.')
                ->required(),
            'objective' => $schema->string()->description('What the project is meant to achieve.'),
            'description' => $schema->string()->description('Longer background for the project.'),
            'status' => $schema->string()
                ->enum(array_column(ProjectStatusEnum::cases(), 'value'))
                ->description('Defaults to draft.'),
            'priority' => $schema->integer()->description('Higher sorts first. Defaults to 0.'),
            'deadline_at' => $schema->string()->description('Deadline as an ISO-8601 date or datetime.'),
            'workspace_id' => $schema->integer()
                ->description('Workspace to file it under. Defaults to the company default workspace.'),
        ];
    }

    #[Override]
    protected function execute(Request $request, ActingContext $ctx): array
    {
        $input = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'agent_id' => ['required', 'integer'],
            'objective' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'priority' => ['nullable', 'integer'],
            'deadline_at' => ['nullable', 'date'],
            'workspace_id' => ['nullable', 'integer'],
        ]);

        $project = new CreateProjectAction(
            ProjectData::from(
                $ctx->app,
                $ctx->user,
                $ctx->company,
                $input,
            ),
        )->execute();

        return ['project' => $this->presentProject($project)];
    }
}
