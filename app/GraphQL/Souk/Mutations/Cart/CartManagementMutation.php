<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Cart;

use Illuminate\Support\Facades\App;
use Joelwmale\Cart\CartCondition;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Souk\Cart\Actions\AddToCartAction;
use Kanvas\Souk\Cart\Services\CartService;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Models\OrderDiscount;

class CartManagementMutation
{
    public function add(mixed $root, array $request): array
    {
        $user = auth()->user();
        $company = $user ? $user->getCurrentCompany() : app(CompaniesBranches::class)->company;

        if (! $company) {
            throw new ModelNotFoundException('No company found');
        }

        $currentUserCompany = $company;
        $app = app(Apps::class);
        $cart = app('cart')->session(app(AppEnums::KANVAS_IDENTIFIER->getValue()));
        $addToCartAction = new AddToCartAction($app, $currentUserCompany, $user);

        return $addToCartAction->execute($cart, $request['items']);
    }

    public function update(mixed $root, array $request): array
    {
        $cart = app('cart')->session(app(AppEnums::KANVAS_IDENTIFIER->getValue()));

        if (! $cart->has($request['variant_id'])) {
            return [];
        }

        $updateData = [
            'quantity' => $request['quantity'],
        ];

        // Only handle attributes if they are provided in the request
        if (isset($request['attributes']) && ! empty($request['attributes'])) {
            // Get current item to preserve existing attributes
            $currentItem = $cart->get($request['variant_id']);
            $existingAttributes = $currentItem['attributes'] ?? [];

            // Merge existing attributes with new ones (new ones take precedence)
            $updateData['attributes'] = array_merge($existingAttributes, $request['attributes']);
        }

        $cart->update($request['variant_id'], $updateData);

        return $cart->getContent()->toArray();
    }

    public function remove(mixed $root, array $request): array
    {
        $user = auth()->user();
        $cart = app('cart')->session(app(AppEnums::KANVAS_IDENTIFIER->getValue()));

        $cart->remove($request['variant_id']);

        return $cart->getContent()->toArray();
    }

    public function discountCodesUpdate(mixed $root, array $request): array
    {
        $user = auth()->user();
        $cart = app('cart')->session(app(AppEnums::KANVAS_IDENTIFIER->getValue()));
        $app = app(Apps::class);
        $company = $user ? $user->getCurrentCompany() : app(CompaniesBranches::class)->company;

        $discountCodes = $request['discountCodes'] ?? [];
        
        // Clear existing discount conditions
        $cart->clearConditions('discount');

        if (empty($discountCodes)) {
            $cartService = new CartService($cart);
            return $cartService->getCart();
        }

        // Process discount codes using the new discount system
        foreach ($discountCodes as $discountCode) {
            try {
                // Find the discount by code
                $discount = Discount::where('code', $discountCode)
                    ->fromApp($app)
                    ->fromCompany($company)
                    ->where('is_active', true)
                    ->first();

                if (!$discount) {
                    throw new ModelNotFoundException('Discount code not found: ' . $discountCode);
                }

                // Validate if discount can be used
                if (!$discount->canBeUsed()) {
                    throw new ModelNotFoundException('Discount code is not valid or has expired: ' . $discountCode);
                }

                // Check usage limits
                if ($discount->isOverUsageLimit()) {
                    throw new ModelNotFoundException('Discount code has reached its usage limit: ' . $discountCode);
                }

                // Check customer usage limit
                if ($user && $discount->is_one_per_customer) {
                    $hasUsed = OrderDiscount::where('discount_id', $discount->id)
                        ->whereHas('order', function ($query) use ($user) {
                            $query->where('users_id', $user->getId());
                        })
                        ->exists();
                    
                    if ($hasUsed) {
                        throw new ModelNotFoundException('You have already used this discount code: ' . $discountCode);
                    }
                }

                // Calculate cart subtotal for validation
                $cartSubtotal = $cart->getSubTotal();
                
                // Check minimum order value
                if ($discount->min_order_value && $cartSubtotal < $discount->min_order_value) {
                    throw new ModelNotFoundException('Minimum order value of $' . $discount->min_order_value . ' required for discount: ' . $discountCode);
                }

                // Apply the discount as a cart condition
                $discountValue = $discount->is_percentage 
                    ? '-' . $discount->value . '%'
                    : '-' . $discount->value;

                $cartCondition = new CartCondition([
                    'name' => $discount->code,
                    'type' => 'discount',
                    'target' => 'subtotal',
                    'value' => $discountValue,
                    'minimum' => $discount->min_order_value ?? 0,
                    'order' => 1,
                    'attributes' => [
                        'discount_id' => $discount->id,
                        'discount_name' => $discount->name,
                        'discount_type' => $discount->discountType->name,
                        'max_discount_amount' => $discount->max_discount_amount
                    ]
                ]);

                // Apply max discount amount if set
                if ($discount->max_discount_amount && $discount->is_percentage) {
                    $potentialDiscount = ($cartSubtotal * $discount->value) / 100;
                    if ($potentialDiscount > $discount->max_discount_amount) {
                        $cartCondition = new CartCondition([
                            'name' => $discount->code,
                            'type' => 'discount',
                            'target' => 'subtotal',
                            'value' => '-' . $discount->max_discount_amount,
                            'minimum' => $discount->min_order_value ?? 0,
                            'order' => 1,
                            'attributes' => [
                                'discount_id' => $discount->id,
                                'discount_name' => $discount->name,
                                'discount_type' => $discount->discountType->name,
                                'max_discount_applied' => true
                            ]
                        ]);
                    }
                }

                $cart->condition($cartCondition);
                
                // Only apply the first valid discount code
                break;
                
            } catch (ModelNotFoundException $e) {
                // If we have multiple codes, try the next one
                if (count($discountCodes) === 1) {
                    throw $e;
                }
                continue;
            }
        }

        $cartService = new CartService($cart);

        return $cartService->getCart();
    }

    public function clear(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $cart = app('cart')->session(app(AppEnums::KANVAS_IDENTIFIER->getValue()));
        $cart->clearAllConditions();

        return $cart->clear();
    }
}
