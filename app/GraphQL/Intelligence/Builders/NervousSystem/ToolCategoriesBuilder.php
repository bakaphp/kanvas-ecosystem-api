<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Builders\NervousSystem;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Capability\Models\ToolCategory;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ToolCategoriesBuilder
{
    public function getCategories(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $app = app(Apps::class);

        $query = ToolCategory::query()
            ->fromAppOrGlobal($app)
            ->active();

        $framework = $this->resolveFramework($args, $app);
        if ($framework !== null && $framework !== '') {
            $query->forFramework($framework);
        }

        return $query;
    }

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
