<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Nuwave\Lighthouse\Schema\TypeRegistry;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Lighthouse's default union type resolver matches on the resolved value's class_basename, which only
 * works when the model's short class name equals its GraphQL type name. A union whose members break that
 * rule (Users → User, Agent → AgentAi, Bill → ScribeBill) extends this and declares the mapping.
 */
abstract class MappedUnionTypeResolver
{
    /** @var array<class-string, string> */
    protected const array MODEL_TO_TYPE_NAME = [];

    public function __construct(
        private readonly TypeRegistry $typeRegistry,
    ) {
    }

    public function __invoke(mixed $root, GraphQLContext $context, ResolveInfo $resolveInfo): Type
    {
        $typeName = static::MODEL_TO_TYPE_NAME[$root::class] ?? class_basename($root);

        return $this->typeRegistry->get($typeName);
    }
}
