<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Listeners;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\UniversalAssistance\Actions\GetUniversalAssistanceQuotationAction;
use Kanvas\Souk\Orders\Models\Order;
use Exception;
use Illuminate\Support\Facades\Log;

class UniversalAssistanceCartListener
{
    /**
     * Handle cart events (Added, Updated, Removed) to generate dynamic quotations
     *
     * @param array $item Cart item data
     */
    public function handle(array $item): void
    {
        try {
            $app = app(Apps::class);

            // Verify if Universal Assistance is enabled for this app
            if (! $this->isUniversalAssistanceEnabled($app)) {
                return;
            }

            // Get current cart
            $cart = app('cart')->session($item['session_key']);

            // Check if there are travel insurance products in cart
            $insuranceItems = $this->getInsuranceItemsFromCart($cart);
            if (empty($insuranceItems)) {
                return;
            }

            // For each insurance product, generate quotations
            foreach ($insuranceItems as $insuranceItem) {
                $this->processInsuranceItemQuotation($app, $insuranceItem, $item);
            }

        } catch (Exception $e) {
            Log::error('UniversalAssistanceCartListener error: ' . $e->getMessage(), [
                'item' => $item,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Check if Universal Assistance is enabled
     */
    protected function isUniversalAssistanceEnabled(Apps $app): bool
    {
        // Check specific UA configuration
        $uaEnabled = $app->get('UNIVERSAL_ASSISTANCE_ENABLED') ?? false;
        $uaUsername = $app->get('UNIVERSAL_ASSISTANCE_USERNAME');
        $uaOrganization = $app->get('UNIVERSAL_ASSISTANCE_ORGANIZATION');

        return $uaEnabled && ! empty($uaUsername) && ! empty($uaOrganization);
    }

    /**
     * Extract insurance products from cart
     */
    protected function getInsuranceItemsFromCart($cart): array
    {
        $insuranceItems = [];

        foreach ($cart->getContent() as $cartItem) {
            // Check if it's a travel insurance product
            if ($this->isInsuranceProduct($cartItem)) {
                $insuranceItems[] = $cartItem;
            }
        }

        return $insuranceItems;
    }

    /**
     * Check if a cart item is an insurance product
     */
    protected function isInsuranceProduct($cartItem): bool
    {
        // Check by product attributes
        $attributes = $cartItem->attributes ?? [];

        // Different ways to identify insurance products
        if (isset($attributes['product_type']) &&
            in_array(strtolower($attributes['product_type']), ['insurance', 'seguro', 'travel_insurance'])) {
            return true;
        }

        if (isset($attributes['category']) &&
            in_array(strtolower($attributes['category']), ['seguros', 'insurance', 'travel'])) {
            return true;
        }

        // Check by product name
        $productName = strtolower($cartItem->name ?? '');
        if (strpos($productName, 'seguro') !== false ||
            strpos($productName, 'insurance') !== false ||
            strpos($productName, 'asistencia') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Process quotation for a specific insurance product
     */
    protected function processInsuranceItemQuotation(Apps $app, $insuranceItem, array $cartEventData): void
    {
        try {
            // Build cartData from cart item
            $cartData = $this->buildCartDataFromCartItem($insuranceItem, $cartEventData);

            if (! $cartData) {
                Log::warning('Could not build cartData for UA quotation', [
                    'item_id' => $insuranceItem->id ?? 'unknown'
                ]);
                return;
            }

            // Build planVariant from product attributes
            $planVariant = $this->buildPlanVariantFromCartItem($insuranceItem);

            // Execute quotation Action
            $quotationResult = GetUniversalAssistanceQuotationAction::run($app, $cartData, $planVariant);

            // Store or process results
            $this->handleQuotationResult($insuranceItem, $quotationResult, $cartEventData);

        } catch (Exception $e) {
            Log::error('Error processing UA quotation for item: ' . ($insuranceItem->id ?? 'unknown'), [
                'error' => $e->getMessage(),
                'item' => $insuranceItem
            ]);
        }
    }

    /**
     * Build cartData from cart item
     */
    protected function buildCartDataFromCartItem($cartItem, array $cartEventData): ?array
    {
        $attributes = $cartItem->attributes ?? [];

        // Try to get order from session or create temporary one
        $order = $this->getOrCreateOrder($cartEventData);
        if (! $order) {
            return null;
        }

        // Extract holder data from cart attributes
        $titular = [
            'firstname' => $attributes['titular_firstname'] ?? $attributes['firstname'] ?? 'John',
            'lastname' => $attributes['titular_lastname'] ?? $attributes['lastname'] ?? 'Doe',
            'email' => $attributes['titular_email'] ?? $attributes['email'] ?? 'test@example.com',
            'idType' => $attributes['titular_id_type'] ?? $attributes['id_type'] ?? 'dni',
            'idNumber' => $attributes['titular_id_number'] ?? $attributes['id_number'] ?? '12345678',
            'dob' => $attributes['titular_dob'] ?? $attributes['dob'] ?? '1990-01-01',
            'activationDate' => $attributes['activation_date'] ?? now()->format('Y-m-d'),
            'originCountryCode' => $attributes['origin_country'] ?? 'DO',
            'destinationCountryCode' => $attributes['destination_country'] ?? 'US',
        ];

        // Extract dependents if they exist
        $dependents = [];
        if (isset($attributes['dependents']) && is_array($attributes['dependents'])) {
            $dependents = $attributes['dependents'];
        }

        return [
            'order' => $order,
            'titular' => $titular,
            'dependents' => $dependents
        ];
    }

    /**
     * Build planVariant from cart item
     */
    protected function buildPlanVariantFromCartItem($cartItem): array
    {
        $attributes = $cartItem->attributes ?? [];

        return [
            'id' => $cartItem->id ?? null,
            'name' => $cartItem->name ?? 'Plan Base',
            'duration' => $attributes['duration'] ?? $attributes['days'] ?? 7,
            'price' => $cartItem->price ?? 0,
            'currency' => $attributes['currency'] ?? 'USD',
            'attributes' => $attributes
        ];
    }

    /**
     * Get or create an order for quotation
     */
    protected function getOrCreateOrder(array $cartEventData): ?Order
    {
        // Try to get order_id from event data
        $orderId = $cartEventData['order_id'] ?? null;
        
        if ($orderId) {
            try {
                return Order::findOrFail($orderId);
            } catch (Exception $e) {
                Log::warning('Order not found: ' . $orderId);
            }
        }

        // If no order, create a temporary one or use default
        // In a real scenario, you would probably need to create a real order
        try {
            // Find a recent user order or create new one
            return Order::first(); // Placeholder - implement real logic
        } catch (Exception $e) {
            Log::error('Could not create/find order for UA quotation');
            return null;
        }
    }

    /**
     * Handle quotation results
     */
    protected function handleQuotationResult($cartItem, array $quotationResult, array $cartEventData): void
    {
        // Here you can decide what to do with results:
        // 1. Store in cache to show dynamic pricing
        // 2. Update cart item attributes
        // 3. Send notification to frontend
        // 4. Logging for analysis

        Log::info('UA Quotation generated for cart item', [
            'cart_item_id' => $cartItem->id ?? 'unknown',
            'inclusion_products' => count($quotationResult['inclusion']['products'] ?? []),
            'cross_selling_products' => count($quotationResult['cross_selling']['products'] ?? []),
            'convention_type' => $quotationResult['request_info']['convention_type'] ?? 'unknown'
        ]);

        // Example: Update cart item attributes with dynamic pricing
        $this->updateCartItemWithQuotation($cartItem, $quotationResult);
    }

    /**
     * Update cart item with quotation information
     */
    protected function updateCartItemWithQuotation($cartItem, array $quotationResult): void
    {
        try {
            // Get cart
            $cart = app('cart');

            // Prepare updated attributes
            $currentAttributes = $cartItem->attributes ?? [];

            // Add quotation information
            $updatedAttributes = array_merge($currentAttributes->toArray(), [
                'ua_quotation_generated' => true,
                'ua_quotation_timestamp' => now()->toISOString(),
                'ua_convention_type' => $quotationResult['request_info']['convention_type'] ?? null,
                'ua_inclusion_products_count' => count($quotationResult['inclusion']['products'] ?? []),
                'ua_cross_selling_products_count' => count($quotationResult['cross_selling']['products'] ?? []),
                // Add main prices if available
                'ua_inclusion_main_price' => $quotationResult['inclusion']['products'][0]['price_emission'] ?? null,
                'ua_cross_selling_main_price' => $quotationResult['cross_selling']['products'][0]['price_emission'] ?? null,
            ]);

            // Update cart item
            $cart->update($cartItem->id, [
                'attributes' => $updatedAttributes
            ]);

        } catch (Exception $e) {
            Log::error('Error updating cart item with UA quotation: ' . $e->getMessage());
        }
    }
}
