<?php

declare(strict_types=1);

namespace App\GraphQL\Directives;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\AppKey;
use Nuwave\Lighthouse\Auth\AuthServiceProvider;
use Nuwave\Lighthouse\Auth\GuardDirective;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Nuwave\Lighthouse\Execution\ResolveInfo;

use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class GuardByAppKeyDirective extends GuardDirective
{
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

                if (! $user->isAn(RolesEnums::OWNER->value)) {
                    throw new AuthorizationException('You are not authorized to perform this action please contact your app owner');
                }

                return $previousResolver($root, $args, $context, $resolveInfo);
            }
        );
    }
}
