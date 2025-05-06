<?php

declare(strict_types=1);

namespace App\GraphQL\Directives;

use Bouncer;
use Illuminate\Auth\Access\AuthorizationException;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;

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
        ) on ARGUMENT_DEFINITION
        GRAPHQL;
    }

    public function handleField(FieldValue $fieldValue): void
    {
        $permission = $this->directiveArgValue('permission');
        $model = $this->directiveArgValue('model');

        if (! Bouncer::can($permission, $model)) {
            throw new AuthorizationException('You are not authorized to perform this action please contact your administrator');
        }
    }
}
