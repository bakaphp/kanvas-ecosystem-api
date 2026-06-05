<?php

declare(strict_types=1);

namespace App\GraphQL\Directives;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Nuwave\Lighthouse\Execution\ResolveInfo;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Override;
use Stripe\Stripe;

class RequiresStripeConfigurationDirective extends BaseDirective implements FieldMiddleware
{
    #[Override]
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
"""
Require the current app to have a Stripe secret key configured before the
resolver runs. Sets the Stripe SDK's global API key for the rest of the
request. Throws ValidationException if not configured.
"""
directive @requiresStripeConfiguration on FIELD_DEFINITION
GRAPHQL;
    }

    #[Override]
    public function handleField(FieldValue $fieldValue): void
    {
        $fieldValue->wrapResolver(
            fn (callable $previousResolver) => /**
             * @param array<string, mixed> $args
             */
            function (
                mixed $root,
                array $args,
                GraphQLContext $context,
                ResolveInfo $resolveInfo,
            ) use ($previousResolver): mixed {
                $secret = app(Apps::class)->get(ConfigurationEnum::STRIPE_SECRET_KEY->value);

                if (! is_string($secret) || $secret === '') {
                    throw new ValidationException('Stripe is not configured for this app');
                }

                Stripe::setApiKey($secret);

                return $previousResolver($root, $args, $context, $resolveInfo);
            }
        );
    }
}
