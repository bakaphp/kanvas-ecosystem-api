<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Types;

use Baka\Contracts\EngageableInterface;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Nuwave\Lighthouse\Schema\TypeRegistry;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Every engageable model names its own GraphQL type, so a new reference only adds a union member.
 */
class EngagementReferenceTypeResolver
{
    public function __construct(
        private readonly TypeRegistry $typeRegistry,
    ) {
    }

    public function __invoke(EngageableInterface $root, GraphQLContext $context, ResolveInfo $resolveInfo): Type
    {
        return $this->typeRegistry->get($root->getGraphTypeName());
    }
}
