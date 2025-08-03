<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries\Orders;

use GraphQL\Type\Definition\ResolveInfo;
use App\GraphQL\Souk\Filters\OrderQueryFilter;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Models\Order;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Kanvas\Souk\Orders\Actions\ExportOrdersAction;

class OrderExportQuery
{
    public function export(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $format = $args['format'];
        
        // Extract field mapper and custom title from args
        $fieldMapper = $args['field_mapper'] ?? null;
        $customTitle = $args['custom_title'] ?? null;
        
        try {
            // Build the query with the same filters as the orders query
            $query = Order::query()
                ->fromCompany()
                ->fromApp()
                ->notDeleted()
                ->filterByUser();

            // Apply search filter
            if (isset($args['search'])) {
                $query->where(function ($q) use ($args) {
                    $searchTerm = $args['search'];
                    $q->where('user_email', 'like', "%{$searchTerm}%")
                      ->orWhere('user_phone', 'like', "%{$searchTerm}%")
                      ->orWhere('reference', 'like', "%{$searchTerm}%")
                      ->orWhere('order_number', 'like', "%{$searchTerm}%");
                });
            }

            // Apply where conditions
            // if (isset($args['where'])) {
            //     OrderQueryFilter::apply($query, $args['where']);
            // }

            // Apply order by
            if (isset($args['orderBy'])) {
                foreach ($args['orderBy'] as $order) {
                    $column = $order['column'];
                    $direction = $order['order'] ?? 'ASC';
                    $query->orderBy($column, $direction);
                }
            } else {
                $query->orderBy('created_at', 'DESC');
            }

            // Get the orders with relationships needed for field mapping
            $orders = $query->with([
                'user', 
                'company', 
                'orderType', 
                'orderStatus', 
                'allItems',
                'allItems.variant',
            ])->get();

            // Create export service with field mapper and custom title
            $exportService = new ExportOrdersAction($orders, $fieldMapper, $customTitle);
            return $exportService->execute($format);
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'download_url' => null,
                'file_name' => null,
                'message' => 'Export failed: ' . $e->getMessage()
            ];
        }
    }
}