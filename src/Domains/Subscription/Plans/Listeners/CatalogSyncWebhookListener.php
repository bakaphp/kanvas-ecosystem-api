<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Plans\Listeners;

use Kanvas\Apps\Models\Apps;
use Kanvas\Subscription\Plans\Models\Plan;
use Kanvas\Subscription\Prices\Models\Price;
use Laravel\Cashier\Events\WebhookReceived;

class CatalogSyncWebhookListener
{
    public function handle(WebhookReceived $event): void
    {
        $type = $event->payload['type'] ?? null;
        if (! is_string($type)) {
            return;
        }

        $object = $event->payload['data']['object'] ?? null;
        if (! is_array($object)) {
            return;
        }

        match ($type) {
            'product.deleted' => $this->softDeletePlan($object),
            'product.updated' => $this->syncPlan($object),
            'price.deleted' => $this->softDeletePrice($object),
            'price.updated' => $this->syncPrice($object),
            default => null,
        };
    }

    private function softDeletePlan(array $object): void
    {
        $plan = $this->findPlan($object);
        $plan?->delete();
    }

    private function syncPlan(array $object): void
    {
        $plan = $this->findPlan($object);
        if ($plan === null) {
            return;
        }

        $plan->update([
            'name' => $object['name'] ?? $plan->name,
            'description' => $object['description'] ?? $plan->description,
            'is_active' => (bool) ($object['active'] ?? $plan->is_active),
        ]);
    }

    private function softDeletePrice(array $object): void
    {
        $price = $this->findPrice($object);
        $price?->delete();
    }

    private function syncPrice(array $object): void
    {
        $price = $this->findPrice($object);
        if ($price === null) {
            return;
        }

        $price->update([
            'is_active' => (bool) ($object['active'] ?? $price->is_active),
        ]);
    }

    private function findPlan(array $object): ?Plan
    {
        $stripeId = $object['id'] ?? null;
        if (! is_string($stripeId) || $stripeId === '') {
            return null;
        }

        return Plan::query()
            ->where('stripe_id', $stripeId)
            ->where('apps_id', app(Apps::class)->getId())
            ->first();
    }

    private function findPrice(array $object): ?Price
    {
        $stripeId = $object['id'] ?? null;
        if (! is_string($stripeId) || $stripeId === '') {
            return null;
        }

        return Price::query()
            ->select('apps_plans_prices.*')
            ->join('apps_plans', 'apps_plans.id', '=', 'apps_plans_prices.apps_plans_id')
            ->where('apps_plans_prices.stripe_id', $stripeId)
            ->where('apps_plans.apps_id', app(Apps::class)->getId())
            ->first();
    }
}
