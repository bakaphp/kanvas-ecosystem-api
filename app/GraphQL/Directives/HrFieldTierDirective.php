<?php

declare(strict_types=1);

namespace App\GraphQL\Directives;

use Closure;
use Kanvas\HumanResources\Compensation\Services\CompensationAccessService;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Execution\ResolveInfo;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Override;

/**
 * Redacts a field to null when the viewer lacks the required HR sensitivity tier.
 * Returns null (not an error) so the rest of the query still resolves — the value
 * never leaves the server for unauthorized viewers.
 */
class HrFieldTierDirective extends BaseDirective implements FieldMiddleware
{
    #[Override]
    public static function definition(): string
    {
        return /** @lang GraphQL */ 'directive @hrFieldTier(tier: HrFieldTierEnum!) on FIELD_DEFINITION';
    }

    #[Override]
    public function handleField(FieldValue $fieldValue): void
    {
        $tier = $this->directiveArgValue('tier');

        $fieldValue->wrapResolver(fn (callable $resolver): Closure => function (mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo) use ($resolver, $tier) {
            if (! $this->allows($context->user(), $tier, $root)) {
                return null;
            }

            return $resolver($root, $args, $context, $resolveInfo);
        });
    }

    private function allows(mixed $viewer, string $tier, mixed $root): bool
    {
        if (! $viewer instanceof Users) {
            return false;
        }

        $access = app(CompensationAccessService::class);

        return match ($tier) {
            'COMPENSATION' => $root instanceof Employee && $access->canViewCompensation($viewer, $root),
            'COMPENSATION_BAND' => $access->canViewBand($viewer),
            default => $viewer->isAdmin(),
        };
    }
}
