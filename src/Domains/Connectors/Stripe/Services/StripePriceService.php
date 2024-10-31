<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Services;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Subscription\Plans\Repositories\PlanRepository;

class StripePriceService
{
    public function __construct(
        protected AppInterface $app,
        protected string $stripePriceId,
        protected ?UserInterface $user = null
    ) {
        $this->user = $user;
    }

    public function mapPriceForImport(array $data): array
    {
        $webhookPrice = $data['data']['object'];

        $amount = $webhookPrice['unit_amount'] / 100;
        $currency = strtoupper($webhookPrice['currency']);
        $interval = $webhookPrice['recurring']['interval'];
        $stripeplan = $webhookPrice['product'];
        $status = $webhookPrice['active'];

        return [
            'amount' => $amount,
            'currency' => $currency,
            'interval' => $interval,
            'stripe_id' => $this->stripePriceId,
            'is_active' => $status,
            'apps_plans_id' => $this->getPlanId($stripeplan),
        ];
    }

    protected function getPlanId(string $productId): int
    {
        $plan = PlanRepository::getByStripeId($productId, $this->app);
        return $plan->id;
    }
}
