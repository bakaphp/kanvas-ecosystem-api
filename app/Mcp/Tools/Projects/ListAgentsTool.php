<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Projects;

use App\GraphQL\Concerns\ActingContext;
use App\Mcp\Tools\KanvasTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Models\Agent;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;

#[IsReadOnly]
class ListAgentsTool extends KanvasTool
{
    protected string $name = 'list_agents';

    protected string $description = 'List the AI agents of the current company. Use it to pick the agent_id a project '
        . 'needs as its manager, or the agent to assign a task to.';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Only agents whose name contains this text.'),
            'limit' => $schema->integer()->min(1)->max(100)->description('Maximum agents to return, default 50.'),
        ];
    }

    #[Override]
    protected function execute(Request $request, ActingContext $ctx): array
    {
        $agents = Agent::query()
            ->fromApp($ctx->app)
            ->fromCompany($ctx->company)
            ->notDeleted()
            ->when(
                $request->get('search'),
                fn ($q, string $search) => $q->where('name', 'like', '%' . $search . '%'),
            )
            ->orderBy('name')
            ->limit(min(100, max(1, (int) $request->get('limit', 50))))
            ->get();

        return [
            'agents' => $agents->map(fn (Agent $agent): array => [
                'id' => $agent->getId(),
                'name' => $agent->name,
            ])->all(),
        ];
    }
}
