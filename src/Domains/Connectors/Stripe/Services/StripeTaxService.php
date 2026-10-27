<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\Cache;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Support\StripeAccount;
use Stripe\StripeClient;

class StripeTaxService
{
    public function client(AppInterface $app, Companies $company): StripeClient
    {
        return StripeAccount::resolve($app, $company)->client();
    }

    public function accountFingerprint(AppInterface $app, Companies $company): string
    {
        $account = StripeAccount::resolve($app, $company);

        $cacheKey = 'stripe_account_fingerprint_' . hash('sha256', (string) $account->secretKey);

        return Cache::remember(
            $cacheKey,
            3600,
            function () use ($account) {
                $client = $account->client();
                $stripeAccount = $client->accounts->retrieve();

                return hash('sha256', $stripeAccount->id);
            }
        );
    }
}
