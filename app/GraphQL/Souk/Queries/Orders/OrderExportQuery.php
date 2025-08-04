<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries\Orders;

use GraphQL\Type\Definition\ResolveInfo;
use App\GraphQL\Souk\Handlers\OrderTypeHandler;
use App\GraphQL\Souk\Handlers\OrderStatusHandler;
use App\GraphQL\Souk\Handlers\HasAddressHandler;
use App\GraphQL\Souk\Handlers\HasPeopleHandler;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Nuwave\Lighthouse\WhereConditions\SQLOperator;
use Kanvas\Souk\Orders\Actions\ExportOrdersAction;

class OrderExportQuery
{
    public function export(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $format = $args['format'];
        
        // Extract field mapper and metadata from args
        $fieldMapper = $args['field_mapper'] ?? null;
        $metadata = $args['metadata'] ?? [];
        $customTitle = $metadata['custom_title'] ?? null;
        
        try {
            // Build the query with the same filters as the orders query
            $query = Order::query()
                ->fromCompany()
                ->fromApp()
                ->notDeleted()
                ->filterByUser();

            // Apply search filter
            if (isset($args['search'])) {
                $searchTerm = $args['search'];
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('user_email', 'like', "%{$searchTerm}%")
                      ->orWhere('user_phone', 'like', "%{$searchTerm}%")
                      ->orWhere('reference', 'like', "%{$searchTerm}%")
                      ->orWhere('order_number', 'like', "%{$searchTerm}%");
                });
            }

            // Apply basic where conditions
            if (isset($args['where']) && is_array($args['where'])) {
                $query = $this->applyWhereConditions(
                    $query,
                    $args['where'] ?? []
                );
            }

            // print_r($query->toSql());
            // die();

            // Apply orderType filter using the handler
            if (isset($args['orderType']) && is_array($args['orderType'])) {
                $handler = new OrderTypeHandler(new SQLOperator());
                $handler($query, $args['orderType'], null, 'and');
            }

            // Apply orderStatus filter using the handler
            if (isset($args['orderStatus']) && is_array($args['orderStatus'])) {
                $handler = new OrderStatusHandler(new SQLOperator());
                $handler($query, $args["orderStatus"], null, 'and');
            }

            // Apply hasAddress filter using the handler
            if (isset($args['hasAddress']) && is_array($args['hasAddress'])) {
                $handler = new HasAddressHandler(new SQLOperator());
                foreach ($args['hasAddress'] as $condition) {
                    if (is_array($condition)) {
                        $handler($query, $condition, null, 'and');
                    }
                }
            }

            // Apply hasItems filter
            if (isset($args['hasItems']) && is_array($args['hasItems'])) {
                foreach ($args['hasItems'] as $condition) {
                    // Skip if condition is not an array or doesn't have required fields
                    if (!is_array($condition) || !isset($condition['column']) || !isset($condition['value'])) {
                        continue;
                    }
                    
                    $column = $condition['column'];
                    $operator = $condition['operator'] ?? 'EQ';
                    $value = $condition['value'];
                    
                    $query->whereHas('allItems', function ($q) use ($column, $operator, $value) {
                        switch ($operator) {
                            case 'EQ':
                                $q->where($column, $value);
                                break;
                            case 'LIKE':
                                $q->where($column, 'like', "%{$value}%");
                                break;
                            case 'IN':
                                $q->whereIn($column, is_array($value) ? $value : [$value]);
                                break;
                            // Add more operators as needed
                        }
                    });
                }
            }

            // Apply hasPeople filter using the handler
            if (isset($args['hasPeople']) && is_array($args['hasPeople'])) {
                $handler = new HasPeopleHandler(new SQLOperator());
                foreach ($args['hasPeople'] as $condition) {
                    if (is_array($condition)) {
                        $handler($query, $condition, null, 'and');
                    }
                }
            }

            // Apply order by
            if (isset($args['orderBy']) && is_array($args['orderBy'])) {
                foreach ($args['orderBy'] as $order) {
                    // Skip if order is not an array or doesn't have required fields
                    if (!is_array($order) || !isset($order['column'])) {
                        continue;
                    }
                    
                    $column = $order['column'];
                    $direction = $order['order'] ?? 'ASC';
                    
                    // Convert column name to lowercase to handle enum-like values
                    if (is_string($column)) {
                        $column = strtolower($column);
                    }
                    
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

            // Create export service with field mapper, metadata, and where conditions
            $exportService = new ExportOrdersAction($orders, $fieldMapper, $metadata, $args['where'] ?? []);
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

    public function applyWhereConditions($query, array $conditions = [])
    {
        $operatorMap = [
            'EQ' => '=',
            'NEQ' => '!=',
            'GT' => '>',
            'GTE' => '>=',
            'LT' => '<',
            'LTE' => '<=',
            'LIKE' => 'LIKE',
        ];

        // Handle main condition
        if (isset($conditions['column'], $conditions['value'])) {
            $this->applySingleCondition($query, $conditions, $operatorMap);
        }

        // Handle AND conditions
        if (isset($conditions['AND']) && is_array($conditions['AND'])) {
            foreach ($conditions['AND'] as $andCondition) {
                if (is_array($andCondition) && isset($andCondition['column'], $andCondition['value'])) {
                    $this->applySingleCondition($query, $andCondition, $operatorMap);
                }
            }
        }

        return $query;
    }

    private function applySingleCondition($query, array $condition, array $operatorMap)
    {
        $column = $condition['column'];
        $operator = strtoupper($condition['operator'] ?? 'EQ');
        $value = $condition['value'];

        // Convert column name to lowercase to handle enum-like values
        if (is_string($column)) {
            $column = strtolower($column);
        }

        if ($operator === 'BETWEEN' && is_array($value) && count($value) >= 2) {
            $query->whereBetween($column, [$value[0], $value[1]]);
        } elseif (in_array($operator, ['IN', 'NOT_IN'])) {
            $method = $operator === 'IN' ? 'whereIn' : 'whereNotIn';
            $query->{$method}($column, (array) $value);
        } elseif (array_key_exists($operator, $operatorMap)) {
            $query->where($column, $operatorMap[$operator], $value);
        }
    }

}