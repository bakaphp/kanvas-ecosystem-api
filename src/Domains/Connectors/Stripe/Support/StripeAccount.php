<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Support;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Stripe\StripeClient;

/**
 * A company with its own secret key is its own Stripe account; every other company on the app
 * shares the app's account.
 */
final class StripeAccount
{
    private function __construct(
        public readonly ?string $secretKey,
        public readonly bool $sharedAppAccount
    ) {
    }

    public static function resolve(AppInterface $app, ?Companies $company = null): self
    {
        $companyKey = Str::trimToNull((string) ($company?->get(ConfigurationEnum::STRIPE_SECRET_KEY->value) ?? ''));

        return new self(
            secretKey: $companyKey ?? $app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value),
            sharedAppAccount: $companyKey === null,
        );
    }

    public function client(): StripeClient
    {
        return new StripeClient($this->secretKey);
    }
}
