<?php

namespace App\GraphQL\Directives;

use Closure;
use Kanvas\Companies\Models\Companies;
use Nuwave\Lighthouse\Auth\GuardDirective;
use Nuwave\Lighthouse\Execution\ResolveInfo;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Throwable;

class GuardByCompanyDirective extends GuardDirective
{
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
directive @guardByCompany(
  """
  Specify which guards to use, e.g. ["web"].
  When not defined, the default from `lighthouse.php` is used.
  """
  with: [String!]
) repeatable on FIELD_DEFINITION | OBJECT
GRAPHQL;
    }

    public function handleField(FieldValue $fieldValue, Closure $next): FieldValue
    {
        $previousResolver = $fieldValue->getResolver();

        $fieldValue->setResolver(function (
            $root,
            array $args,
            GraphQLContext $context,
            ResolveInfo $resolveInfo
        ) use ($previousResolver) {
            $request = $context->request();

            if (!$request->headers->has('Company-Authorization')) {
                $this->unauthenticated(['No Company Specified']);
            }

            try {
                Companies::getByUuid(
                    $request->headers->get('Company-Authorization')
                );
            } catch (Throwable $e) {
                $this->unauthenticated(['Invalid Company']);
            }

            return $previousResolver(
                $root,
                $args,
                $context,
                $resolveInfo
            );
        });

        return $next($fieldValue);
    }
}
