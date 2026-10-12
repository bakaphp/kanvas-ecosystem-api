<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Projects;

use App\GraphQL\Concerns\ActingContext;
use App\Mcp\Tools\KanvasTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\NervousSystem\Project\Actions\DeleteProjectAction;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Override;

#[IsDestructive]
class DeleteProjectTool extends KanvasTool
{
    protected string $name = 'delete_project';

    protected string $description = 'Delete an AI project together with its tasks, subtasks and members. Only call this '
        . 'when the person explicitly asked to delete the project.';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('The project to delete.')->required(),
        ];
    }

    #[Override]
    protected function execute(Request $request, ActingContext $ctx): array
    {
        $project = $this->findProject((int) $request->get('project_id'), $ctx);

        return [
            'project_id' => $project->getId(),
            'deleted' => new DeleteProjectAction($project)->execute(),
        ];
    }
}
