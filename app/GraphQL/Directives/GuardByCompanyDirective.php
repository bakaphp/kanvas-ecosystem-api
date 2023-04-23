<?php
declare(strict_types=1);

namespace App\GraphQL\Directives;

use Kanvas\Companies\Models\CompaniesBranches;
use Nuwave\Lighthouse\Auth\GuardDirective;
use Nuwave\Lighthouse\Execution\ResolveInfo;

use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

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

                if (! app()->bound(CompaniesBranches::class)) {
                    $this->unauthenticated(['No Company Branched Specified']);
                }

                return $previousResolver($root, $args, $context, $resolveInfo);
            }
        );
    }
}
