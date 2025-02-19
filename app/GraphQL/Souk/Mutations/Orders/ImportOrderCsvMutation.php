<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Actions\ProcessOrderItemAction;

class ImportOrderCsvMutation
{
    public function create(mixed $root, array $request)
    {
        $user = auth()->user();
        $currentUserCompany = $user->getCurrentCompany();
        $app = app(Apps::class);

        try {
            $processOrderItemAction = new ProcessOrderItemAction($app, $user, $currentUserCompany);
            return $processOrderItemAction->execute($request['input']['file'], (int) $request['input']['channel_id']);
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}
