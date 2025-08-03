<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Actions\ExportOrdersAction;
use Kanvas\Souk\Orders\Models\Order;

class ExportOrderMutation
{
    public function export(mixed $root, array $request): array
    {
        $user = auth()->user();
        $currentUserCompany = $user->getCurrentCompany();
        $app = app(Apps::class);

        try {
            $format = $request['input']['format'];

            // Fetch orders for the current company and app
            $orders = Order::where('companies_id', $currentUserCompany->getId())
                          ->where('apps_id', $app->getId())
                          ->get();

            // Create export service with order data
            $exportService = new ExportOrdersAction($orders);
            $result = $exportService->execute($format);

            return $result;
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'download_url' => null,
                'file_name' => null
            ];
        }
    }
}
