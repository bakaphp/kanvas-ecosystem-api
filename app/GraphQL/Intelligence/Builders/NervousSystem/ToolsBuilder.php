<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Builders\NervousSystem;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ToolsBuilder
{
    public function getTools(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $app = app(Apps::class);

        $query = Tool::query()
            ->fromAppOrGlobal($app)
            ->active();

        $framework = $this->resolveFramework($args, $app);
        if ($framework !== null && $framework !== '') {
            $query->forFramework($framework);
        }

        return $query;
    }

    /**
     * Explicit `framework` wins. Otherwise derive it from the agent type's
     * `provider` so the frontend can just pass `agent_type_id` without
     * knowing about the framework concept.
     */
    private function resolveFramework(array $args, Apps $app): ?string
    {
        if (isset($args['framework']) && $args['framework'] !== '') {
            return (string) $args['framework'];
        }

        if (! isset($args['agent_type_id'])) {
            return null;
        }

        $type = AgentType::getById((int) $args['agent_type_id'], app: $app);

        return $type->provider;
    }
}
