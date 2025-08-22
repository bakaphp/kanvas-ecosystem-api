<?php

declare(strict_types=1);

namespace Kanvas\Souk\Cart\Activities;

use Baka\Contracts\AppInterface;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Kanvas\Souk\Cart\Enums\CartStatusEnum;
use Kanvas\Souk\Cart\Models\Cart;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Activity to sync cart data between Redis and database
 */
class SyncCartToDatabaseActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 2;
    public $timeout = 60;

    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        // Validate required parameters
        $cartSessionId = $params['cart_session_id'] ?? null;
        if (! $cartSessionId) {
            throw new Exception('cart_session_id is required for cart synchronization');
        }

        try {
            // Find or create cart
            $cart = $this->findOrCreateCart($app, $cartSessionId, $params);

            // Update cart with new data
            $this->updateCartData($cart, $params);

            return [
                'success' => true,
                'cart_id' => $cart->getId(),
                'cart_uuid' => $cart->uuid,
                'action' => $cart->wasRecentlyCreated ? 'created' : 'updated',
                'status' => $cart->status,
                'message' => 'Cart synchronized successfully',
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'cart_session_id' => $cartSessionId,
            ];
        }
    }

    /**
     * Find existing cart or create a new one
     */
    private function findOrCreateCart(AppInterface $app, string $cartSessionId, array $params): Cart
    {
        // Try to find existing cart by session ID
        $cart = Cart::where('cart_session_id', $cartSessionId)
            ->where('apps_id', $app->getId())
            ->first();

        if ($cart) {
            return $cart;
        }

        // Create new cart
        return $this->createNewCart($app, $cartSessionId, $params);
    }

    /**
     * Create a new cart record
     */
    private function createNewCart(AppInterface $app, string $cartSessionId, array $params): Cart
    {
        $cart = new Cart();
        $cart->uuid = Str::uuid();
        $cart->apps_id = $app->getId();
        $cart->companies_id = $app->company_id;
        $cart->cart_session_id = $cartSessionId;
        $cart->status = CartStatusEnum::PENDING->value;

        // Set user if provided
        if (! empty($params['user_id'])) {
            $cart->users_id = $params['user_id'];
        }

        // Set email if provided
        if (! empty($params['email'])) {
            $cart->email = $params['email'];
        }

        // Initialize metadata
        $cart->metadata = [
            'created_at' => Carbon::now()->toISOString(),
            'source' => $params['source'] ?? 'web',
        ];

        $cart->save();

        return $cart;
    }

    /**
     * Update cart data with new information
     */
    private function updateCartData(Cart $cart, array $params): void
    {
        $updated = false;

        // Update user if provided and not already set
        if (! empty($params['user_id']) && ! $cart->users_id) {
            $cart->users_id = $params['user_id'];
            $updated = true;
        }

        // Update email if provided and not already set
        if (! empty($params['email']) && ! $cart->email) {
            $cart->email = $params['email'];
            $updated = true;
        }

        // Update cart financial data
        if (isset($params['amount'])) {
            $cart->amount = $params['amount'];
            $updated = true;
        }

        if (isset($params['currency'])) {
            $cart->currency = $params['currency'];
            $updated = true;
        }

        // Update metadata
        $metadata = $cart->metadata ?? [];
        $metadata['last_updated'] = Carbon::now()->toISOString();

        // Add cart data from Redis
        if (! empty($params['cart_data'])) {
            $cartData = $params['cart_data'];
            $metadata['items_count'] = $cartData['items_count'] ?? 0;
            $metadata['last_activity'] = Carbon::now()->toISOString();
        }

        // Add source information
        if (! empty($params['source'])) {
            $metadata['source'] = $params['source'];
        }

        // Merge additional metadata
        if (! empty($params['metadata']) && is_array($params['metadata'])) {
            $metadata = array_merge($metadata, $params['metadata']);
        }

        $cart->metadata = $metadata;
        $updated = true;

        if ($updated) {
            $cart->save();
        }
    }
}
