<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Actions\ProcessOrderItemAction;

class ImportOrderCsvMutation
{
    public function create(mixed $root, array $request): array
    {
        $user = auth()->user();
        $currentUserCompany = $user->getCurrentCompany();
        $app = Apps::getById($request['input']['app_id']);
        $cart = app('cart')->session($user->getId());

        try {
            $processOrderItemAction = new ProcessOrderItemAction($app, $user, $currentUserCompany);

            return $processOrderItemAction->execute(
                $request['input']['file'],
                (int) $request['input']['channel_id'],
                $cart
            );
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }
}
