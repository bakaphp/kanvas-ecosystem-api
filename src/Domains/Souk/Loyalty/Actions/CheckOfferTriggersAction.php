<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Actions;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Souk\Loyalty\Enums\TriggerType;
use Kanvas\Souk\Loyalty\Models\LoyaltyOffer;
use Kanvas\Souk\Loyalty\Models\LoyaltyOfferAssignment;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class CheckOfferTriggersAction
{
    public function __construct(
        private Users $user,
        private Order $order
    ) {
    }

    public function execute(): array
    {
        $triggeredOffers = [];

        // Get all active offers for the app
        $offers = LoyaltyOffer::where('apps_id', $this->order->apps_id)
            ->where('status', 'active')
            ->get();

        foreach ($offers as $offer) {
            if ($this->shouldTriggerOffer($offer)) {
                $assignment = $this->triggerOffer($offer);
                if ($assignment) {
                    $triggeredOffers[] = $assignment;
                }
            }
        }

        return $triggeredOffers;
    }

    /**
     * Check if offer should be triggered based on current order.
     */
    private function shouldTriggerOffer(LoyaltyOffer $offer): bool
    {
        $triggerType = TriggerType::from($offer->trigger_type);
        $triggerValue = $offer->trigger_value;

        return match ($triggerType) {
            TriggerType::FIRST_PURCHASE => $this->isFirstPurchaseEver(),
            TriggerType::FIRST_PRODUCT_TYPE => $this->isFirstProductType($triggerValue),
            TriggerType::FIRST_CATEGORY => $this->isFirstProductType($triggerValue),
            TriggerType::FIRST_TIER_PURCHASE => $this->isFirstTierPurchase($triggerValue),
            TriggerType::MILESTONE => $this->checkMilestone($triggerValue),
            default => false
        };
    }

    /**
     * Check if this is the user's first purchase ever.
     */
    private function isFirstPurchaseEver(): bool
    {
        return Order::fromApp($this->order->app)
            ->where('users_id', $this->user->getId())
            ->where('status', 'completed')
            ->count() === 1; // This order is the first completed
    }

    /**
     * Check if this is the user's first purchase of a specific product type or category.
     */
    private function isFirstProductType(array $triggerValue): bool
    {
        $productTypes = isset($triggerValue['product_types']) && is_array($triggerValue['product_types'])
            ? $triggerValue['product_types']
            : [];
        $productCategories = isset($triggerValue['product_categories']) && is_array($triggerValue['product_categories'])
            ? $triggerValue['product_categories']
            : [];
        $productAttributes = isset($triggerValue['product_attributes']) && is_array($triggerValue['product_attributes'])
            ? $triggerValue['product_attributes']
            : [];

        if (count($productTypes) === 0 && count($productCategories) === 0 && count($productAttributes) === 0) {
            return false;
        }

        // Check if this order contains matching items
        $hasMatchingItems = $this->order->orderItems()
            ->whereHas('variant.product', function (Builder $query) use ($productTypes, $productCategories, $productAttributes): void {
                $this->buildProductTypeQuery($query, $productTypes, $productCategories, $productAttributes);
            })
            ->exists();

        if (! $hasMatchingItems) {
            return false;
        }

        // Check if user has any previous orders with matching product types
        $previousMatches = Order::fromApp($this->order->app)
            ->where('users_id', $this->user->getId())
            ->where('id', '!=', $this->order->getId())
            ->where('status', 'completed')
            ->whereHas('orderItems.variant.product', function (Builder $query) use ($productTypes, $productCategories, $productAttributes): void {
                $this->buildProductTypeQuery($query, $productTypes, $productCategories, $productAttributes);
            })
            ->count();

        return $previousMatches === 0;
    }

    /**
     * Build product type query based on configuration.
     */
    private function buildProductTypeQuery(
        Builder $query,
        array $productTypes,
        array $productCategories,
        array $productAttributes
    ): void {
        $query->where(function (Builder $q) use ($productTypes, $productCategories, $productAttributes): void {
            $hasConditions = false;

            // Check product types
            if (count($productTypes) > 0) {
                $q->whereIn('product_type', $productTypes);
                $hasConditions = true;
            }

            // Check product categories
            if (count($productCategories) > 0) {
                if ($hasConditions) {
                    $q->orWhereHas('categories', function (Builder $categoryQuery) use ($productCategories): void {
                        $categoryQuery->whereIn('name', $productCategories)
                            ->orWhereIn('slug', $productCategories);
                    });
                } else {
                    $q->whereHas('categories', function (Builder $categoryQuery) use ($productCategories): void {
                        $categoryQuery->whereIn('name', $productCategories)
                            ->orWhereIn('slug', $productCategories);
                    });
                    $hasConditions = true;
                }
            }

            // Check product attributes
            if (count($productAttributes) > 0) {
                foreach ($productAttributes as $attributeName => $attributeValue) {
                    if (is_string($attributeName) && ($attributeValue !== null)) {
                        if ($hasConditions) {
                            $q->orWhereHas('attributes', function (Builder $attributeQuery) use ($attributeName, $attributeValue): void {
                                $attributeQuery->where('name', '=', $attributeName)
                                    ->where('value', '=', (string)$attributeValue);
                            });
                        } else {
                            $q->whereHas('attributes', function (Builder $attributeQuery) use ($attributeName, $attributeValue): void {
                                $attributeQuery->where('name', '=', $attributeName)
                                    ->where('value', '=', (string)$attributeValue);
                            });
                            $hasConditions = true;
                        }
                    }
                }
            }
        });
    }

    /**
     * Check if this is user's first purchase in a specific tier/price range.
     */
    private function isFirstTierPurchase(array $triggerValue): bool
    {
        $tierPrice = isset($triggerValue['price']) ? (float)$triggerValue['price'] : null;
        $productSku = isset($triggerValue['product_sku']) ? (string)$triggerValue['product_sku'] : null;

        if ($tierPrice === null && $productSku === null) {
            return false;
        }

        // Check if current order matches the tier criteria
        $matchesCurrentOrder = false;

        if ($tierPrice !== null) {
            $matchesCurrentOrder = abs((float)$this->order->total - $tierPrice) < 0.01;
        }

        if ($productSku !== null) {
            $matchesCurrentOrder = $this->order->orderItems()
                ->whereHas('variant.product', function (Builder $query) use ($productSku): void {
                    $query->where('sku', $productSku);
                })
                ->exists();
        }

        if ($matchesCurrentOrder === false) {
            return false;
        }

        // Check if user has previous purchases in this tier
        $previousTierPurchases = Order::fromApp($this->order->app)
            ->where('users_id', $this->user->getId())
            ->where('id', '!=', $this->order->getId())
            ->where('status', 'completed')
            ->when($tierPrice !== null, function (Builder $query, float $price): Builder {
                return $query->where('total', $price);
            })
            ->when($productSku !== null, function (Builder $query, string $sku): Builder {
                return $query->whereHas('orderItems.variant.product', function (Builder $q) use ($sku): void {
                    $q->where('sku', $sku);
                });
            })
            ->count();

        return $previousTierPurchases === 0;
    }

    /**
     * Check milestone achievements.
     */
    private function checkMilestone(array $triggerValue): bool
    {
        $milestoneType = isset($triggerValue['type']) ? (string)$triggerValue['type'] : null;
        $milestoneValue = isset($triggerValue['value']) ? $triggerValue['value'] : null;

        if ($milestoneType === null || $milestoneValue === null) {
            return false;
        }

        return match ($milestoneType) {
            'total_spent' => $this->checkTotalSpentMilestone((float)$milestoneValue),
            'order_count' => $this->checkOrderCountMilestone((int)$milestoneValue),
            default => false
        };
    }

    /**
     * Check total spent milestone.
     */
    private function checkTotalSpentMilestone(float $targetAmount): bool
    {
        $totalSpent = (float)Order::fromApp($this->order->app)
            ->where('users_id', $this->user->getId())
            ->where('status', 'completed')
            ->sum('total');

        // Check if this order pushes user over the milestone
        $previousTotal = $totalSpent - (float)$this->order->total;

        return $previousTotal < $targetAmount && $totalSpent >= $targetAmount;
    }

    /**
     * Check order count milestone.
     */
    private function checkOrderCountMilestone(int $targetCount): bool
    {
        $orderCount = Order::fromApp($this->order->app)
            ->where('users_id', $this->user->getId())
            ->where('status', 'completed')
            ->count();

        return $orderCount === $targetCount;
    }

    /**
     * Trigger the offer by creating an assignment.
     */
    private function triggerOffer(LoyaltyOffer $offer): ?LoyaltyOfferAssignment
    {
        if (! $this->shouldTriggerOffer($offer)) {
            return null;
        }

        // Check if user already has this offer assigned
        $existingAssignment = LoyaltyOfferAssignment::fromApp($this->order->app)
            ->where('users_id', $this->user->getId())
            ->where('loyalty_offers_id', $offer->getId())
            ->where('status', '!=', 'expired')
            ->first();

        if ($existingAssignment) {
            return $existingAssignment;
        }

        // Assign offer to user
        $assignOfferAction = new AssignOfferAction($this->user, $offer);

        return $assignOfferAction->execute();
    }
}
