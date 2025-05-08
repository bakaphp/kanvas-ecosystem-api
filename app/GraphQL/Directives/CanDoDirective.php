<?php

declare(strict_types=1);

namespace App\GraphQL\Directives;

use Bouncer;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Auth\Access\AuthorizationException;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class CanDoDirective extends BaseDirective implements FieldMiddleware
{
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
        """
        Automatically generates an input argument based on a type.
        """
        directive @canDo(
            """
            The name of the type to use as the basis for the input type.
            """
            permission: String!
            model: String!
        ) on FIELD_DEFINITION
        GRAPHQL;
    }

    public function handleField(FieldValue $fieldValue): void
    {
        $fieldValue->wrapResolver(fn (callable $resolver) => function (mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo) use ($resolver) {
            // Do something before the resolver, e.g. validate $args, check authentication

            $permission = $this->directiveArgValue('permission');
            $model = $this->directiveArgValue('model');
            if (! class_exists($model)) {
                throw new AuthorizationException('Model not found');
            }
            if ($id = $this->directiveArgValue('id')) {
                $entity = $model::find($id);
                if ($entity->isEntityOwner(auth()->user())) {
                    return;
                }
            }
            $entity = $model::find($this->directiveArgValue('id'));
            if (! Bouncer::can($permission, $model)) {
                throw new AuthorizationException('You are not authorized to perform this action please contact your administrator');
            }
            $result = $resolver($root, $args, $context, $resolveInfo);
            return $result;
        });
    }
}
