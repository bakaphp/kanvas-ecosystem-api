<?php

declare(strict_types=1);

namespace App\GraphQL\Directives;

use Kanvas\Enums\AppEnums;
use Nuwave\Lighthouse\Auth\GuardDirective;
use Nuwave\Lighthouse\Execution\ResolveInfo;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Override;

class GuardByKanvasIdentifierDirective extends GuardDirective
{
    #[Override]
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
        directive @guardByKanvasIdentifier(
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
                $request = $context->request();
                $kanvasIdentifier = AppEnums::KANVAS_IDENTIFIER->getValue();
                if (! app($kanvasIdentifier)) {
                    $this->unauthenticated(['No Cart Session Identifier']);
                }

                return $previousResolver($root, $args, $context, $resolveInfo);
            }
        );
    }
}
