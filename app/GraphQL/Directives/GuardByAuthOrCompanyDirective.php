<?php

namespace App\GraphQL\Directives;

use Kanvas\Companies\Models\Companies;
use Nuwave\Lighthouse\Auth\AuthServiceProvider;
use Nuwave\Lighthouse\Auth\GuardDirective;
use Nuwave\Lighthouse\Execution\ResolveInfo;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Throwable;

class GuardByAuthOrCompanyDirective extends GuardDirective
{
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
directive @guardByAuthOrCompany(
  """
  Specify which guards to use, e.g. ["web"].
  When not defined, the default from `lighthouse.php` is used.
  """
  with: [String!]
) repeatable on FIELD_DEFINITION | OBJECT
GRAPHQL;
    }

    public function handleField(FieldValue $fieldValue): void
    {
        $fieldValue->wrapResolver(
            fn (callable $previousResolver) => function (
                $root,
                array $args,
                GraphQLContext $context,
                ResolveInfo $resolveInfo
            ) use ($previousResolver) {
                $request = $context->request();

                if (! $request->headers->has('Company-Authorization') && ! $request->headers->has('Authorization')) {
                    $this->unauthenticated([]);
                } elseif ($request->headers->has('Authorization')) {
                    // TODO remove cast in v6
                    $with = (array) $this->directiveArgValue('with', AuthServiceProvider::guards()['api']);
                    $this->authenticate($with);
                } else {
                    try {
                        Companies::getByUuid(
                            $request->headers->get('Company-Authorization')
                        );
                    } catch (Throwable $e) {
                        $this->unauthenticated(['Invalid Company']);
                    }
                }

                return $previousResolver($root, $args, $context, $resolveInfo);
            }
        );
    }
}
