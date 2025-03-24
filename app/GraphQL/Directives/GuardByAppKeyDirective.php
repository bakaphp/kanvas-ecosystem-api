<?php

declare(strict_types=1);

namespace App\GraphQL\Directives;

use Kanvas\Apps\Models\AppKey;
use Nuwave\Lighthouse\Auth\AuthServiceProvider;
use Nuwave\Lighthouse\Auth\GuardDirective;
use Nuwave\Lighthouse\Execution\ResolveInfo;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Override;

class GuardByAppKeyDirective extends GuardDirective
{
    #[Override]
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
directive @guardByAppKey(
  """
  Specify which guards to use, e.g. ["web"].
  When not defined, the default from `lighthouse.php` is used.
  """
  with: [String!]
) repeatable on FIELD_DEFINITION | OBJECT
GRAPHQL;
    }

    #[Override]
    public function handleField(FieldValue $fieldValue): void
    {
        $fieldValue->wrapResolver(
            fn (callable $previousResolver) => function (
                $root,
                array $args,
                GraphQLContext $context,
                ResolveInfo $resolveInfo
            ) use ($previousResolver) {
                if (! app()->bound(AppKey::class)) {
                    $this->unauthenticated(['No App Key configure with this key']);
                }

                $with = (array) $this->directiveArgValue('with', current(AuthServiceProvider::guards()));
                $user = $this->authenticate($with);

                return $previousResolver($root, $args, $context, $resolveInfo);
            }
        );
    }
}
