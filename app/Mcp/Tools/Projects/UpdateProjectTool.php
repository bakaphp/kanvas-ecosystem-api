<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Projects;

use App\GraphQL\Concerns\ActingContext;
use App\Mcp\Tools\Concerns\PresentsProjectBoard;
use App\Mcp\Tools\KanvasTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\NervousSystem\Project\Actions\UpdateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Enums\ProjectStatusEnum;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Override;

#[IsIdempotent]
class UpdateProjectTool extends KanvasTool
{
    use PresentsProjectBoard;

    private const array FIELDS = [
        'title',
        'objective',
        'description',
        'status',
        'priority',
        'deadline_at',
        'agent_id',
    ];

    protected string $name = 'update_project';

    protected string $description = 'Change an AI project. Send only the fields you want to change; anything you leave '
        . 'out keeps its current value.';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('The project to change.')->required(),
            'title' => $schema->string()->description('New project name.'),
            'objective' => $schema->string()->description('New objective.'),
            'description' => $schema->string()->description('New description.'),
            'status' => $schema->string()
                ->enum(array_column(ProjectStatusEnum::cases(), 'value'))
                ->description('New status.'),
            'priority' => $schema->integer()->description('New priority; higher sorts first.'),
            'deadline_at' => $schema->string()->description('New deadline as an ISO-8601 date or datetime.'),
            'agent_id' => $schema->integer()->description('Reassign the project-manager agent.'),
        ];
    }

    #[Override]
    protected function execute(Request $request, ActingContext $ctx): array
    {
        $request->validate([
            'project_id' => ['required', 'integer'],
            'title' => ['sometimes', 'string', 'max:255'],
            'priority' => ['sometimes', 'integer'],
            'deadline_at' => ['sometimes', 'nullable', 'date'],
            'agent_id' => ['sometimes', 'integer'],
        ]);

        $project = $this->findProject((int) $request->get('project_id'), $ctx);

        $project = new UpdateProjectAction(
            $project,
            ProjectData::forUpdate(
                $project,
                $ctx->app,
                $ctx->company,
                $ctx->user,
                $this->sent($request, self::FIELDS),
            ),
        )->execute();

        return ['project' => $this->presentProject($project)];
    }
}
