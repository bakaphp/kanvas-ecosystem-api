<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Builders\NervousSystem;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
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
            ->forApp($app->getId())
            ->active();

        if (isset($args['framework']) && $args['framework'] !== '') {
            $query->forFramework((string) $args['framework']);
        }

        return $query;
    }
}
