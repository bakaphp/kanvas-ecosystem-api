<?php

declare(strict_types=1);

namespace App\GraphQL\Directives;

use Closure;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Execution\ResolveInfo;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Override;

class AdminOnlyFieldDirective extends BaseDirective implements FieldMiddleware
{
    #[Override]
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
"""
Redacts a field's value for non-admin users.

The field resolves to its real value only for admins/owners; every other
authenticated user receives `null` instead. The field MUST be nullable in
the schema — on a non-null field the redaction would null-propagate to the
parent object instead of just hiding the value.
"""
directive @adminOnlyField on FIELD_DEFINITION
GRAPHQL;
    }

    #[Override]
    public function handleField(FieldValue $fieldValue): void
    {
        $fieldValue->wrapResolver(
            fn (callable $resolver): Closure => function (
                mixed $root,
                array $args,
                GraphQLContext $context,
                ResolveInfo $resolveInfo
            ) use ($resolver): mixed {
                /** @var Users|null $user */
                $user = $context->user();

                return $user?->isAdmin() === true
                    ? $resolver($root, $args, $context, $resolveInfo)
                    : null;
            }
        );
    }
}
