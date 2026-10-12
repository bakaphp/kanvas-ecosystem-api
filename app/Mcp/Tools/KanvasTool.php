<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\GraphQL\Concerns\ActingContext;
use App\GraphQL\Concerns\ResolvesActingContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Exceptions\ModelNotFoundException as KanvasModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Project\Models\Project;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use ValueError;

/**
 * Every id reaching a tool is model-supplied, so each lookup goes through the tenant resolved by
 * the X-Kanvas-* headers (EnsureKanvasMcpContext) — never an unscoped find.
 *
 * Board vocabulary differs from the domain's: a board "task" is a NervousSystem Plan and a
 * "subtask" is a NervousSystem Task.
 */
abstract class KanvasTool extends Tool
{
    use ResolvesActingContext;

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            return Response::structured($this->execute($request, $this->actingContext()));
        } catch (ModelNotFoundException|KanvasModelNotFoundException) {
            return Response::error('Record not found in this company. Check the id with a list tool before retrying.');
        } catch (ValidationException $e) {
            return Response::error($e->getMessage());
        } catch (ValueError) {
            return Response::error('Invalid status value. Use one of the values listed in the tool schema.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function execute(Request $request, ActingContext $ctx): array;

    protected function findProject(int $id, ActingContext $ctx): Project
    {
        /** @var Project $project */
        $project = Project::getByIdFromCompanyApp($id, $ctx->company, $ctx->app);

        return $project;
    }

    protected function findTask(int $id, ActingContext $ctx): Plan
    {
        /** @var Plan $plan */
        $plan = Plan::getByIdFromCompanyApp($id, $ctx->company, $ctx->app);

        return $plan;
    }

    protected function findSubtask(int $id, ActingContext $ctx): Task
    {
        /** @var Task $task */
        $task = Task::getByIdFromCompanyApp($id, $ctx->company, $ctx->app);

        return $task;
    }

    /**
     * Only the keys the caller actually sent: the update DTOs treat a present null as "clear this
     * field" and an absent key as "keep it", so defaults must not be filled in here.
     *
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    protected function sent(Request $request, array $keys): array
    {
        return array_intersect_key($request->all(), array_flip($keys));
    }
}
