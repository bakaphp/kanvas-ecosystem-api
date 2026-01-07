<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Exception;
use Illuminate\Http\UploadedFile;
use Joelwmale\Cart\Cart;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Cart\Actions\AddToCartAction;
use Kanvas\Souk\Orders\Jobs\ProcessOrderItemJob;
use Kanvas\Souk\Orders\Services\OrderItemService;
use Kanvas\Users\Models\Users;

class ProcessOrderItemAction
{
    private const int LIMIT_ITEMS_PER_REQUEST = 700;

    public function __construct(
        protected Apps $app,
        protected Users $user,
        protected Companies $currentUserCompany
    ) {
    }

    public function execute(UploadedFile $file, int $channelId, Cart $cart): array
    {
        $orderItemService = new OrderItemService($this->app, $this->user, $this->currentUserCompany);
        $items = $orderItemService->getOrderItemsFromCsv($file);

        // Get the valid order items.
        $validationResult = $orderItemService->getValidOrderItems($items);
        $validOrderItems = $validationResult['validItems'];
        $notFoundErrors = $validationResult['errors'];
        $validOrderItemsCount = count($validOrderItems);

        if ($validOrderItemsCount === 0) {
            throw new Exception('No valid order items found');
        }

        // If the number of valid order items is greater than the limit, dispatch a job to process the order items.
        if ($validOrderItemsCount > self::LIMIT_ITEMS_PER_REQUEST) {
            ProcessOrderItemJob::dispatch(
                $this->app,
                $this->user,
                $this->currentUserCompany,
                $validOrderItems,
                $channelId,
                $cart->getSessionKey()
            );

            return [
                'status' => 'pending',
                'message' => 'Items processed in queue' ,
            ];
        }

        // Process the order items and add to cart.
        $result = $orderItemService->processOrderItems($validOrderItems, $channelId);

        // Merge errors from both validation steps
        $allErrors = array_merge($notFoundErrors, $result['errors']);

        if (count($allErrors) > 0) {
            throw new Exception(implode(', ', $allErrors));
        }

        // Add the items to the cart.
        $addToCartAction = new AddToCartAction($this->app, $this->currentUserCompany, $this->user);
        $addToCartAction->execute($cart, $result['validItems']);

        // Return the items processed.
        return [
            'status' => 'success',
            'message' => 'Items processed successfully',
        ];
    }
}
