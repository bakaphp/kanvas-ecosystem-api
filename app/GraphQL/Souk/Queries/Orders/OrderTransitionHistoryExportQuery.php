<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries\Orders;

use App\GraphQL\Souk\Handlers\FromStatusHandler;
use App\GraphQL\Souk\Handlers\OrderTransitionHistoryOrderTypeHandler;
use App\GraphQL\Souk\Handlers\OrderTransitionHistoryProviderHandler;
use App\GraphQL\Souk\Handlers\OrderTransitionHistoryVariantHandler;
use App\GraphQL\Souk\Handlers\ToStatusHandler;
use Exception;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Actions\ExportOrderTransitionHistoryAction;
use Kanvas\Souk\Orders\Models\OrderTransitionHistory;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Nuwave\Lighthouse\WhereConditions\SQLOperator;

class OrderTransitionHistoryExportQuery
{
    public function export(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): array {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $format = $args['format'];
        $fieldMapper = $args['field_mapper'] ?? null;
        $metadata = $args['metadata'] ?? [];
        $timezone = $args['timezone'] ?? $user->timezone ?? null;

        try {
            $query = $this->buildQuery($app, $company, $args);

            if ($query->count() === 0) {
                return [
                    'status' => 'warning',
                    'download_url' => null,
                    'file_name' => null,
                    'message' => 'No transition history records found matching the specified criteria.',
                ];
            }

            $exportService = new ExportOrderTransitionHistoryAction(
                app: $app,
                user: $user,
                data: $query,
                fieldMapper: $fieldMapper,
                metadata: $metadata,
                params: $args['where'] ?? [],
                timezone: $timezone,
            );

            return $exportService->execute($format);
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'download_url' => null,
                'file_name' => null,
                'message' => 'Export failed: ' . $e->getMessage(),
            ];
        }
    }

    public function buildQuery(
        Apps $app,
        Companies $company,
        array $args
    ): Builder {
        $query = OrderTransitionHistory::query()
            ->fromApp($app)
            ->notDeleted();

        if (isset($args['where']) && is_array($args['where'])) {
            $query = $this->applyWhereConditions($query, $args['where']);
        }

        if (isset($args['toStatus']) && is_array($args['toStatus'])) {
            $handler = new ToStatusHandler(new SQLOperator());
            $handler($query, $args['toStatus'], null, 'and');
        }

        if (isset($args['fromStatus']) && is_array($args['fromStatus'])) {
            $handler = new FromStatusHandler(new SQLOperator());
            $handler($query, $args['fromStatus'], null, 'and');
        }

        if (isset($args['hasOrder']) && is_array($args['hasOrder'])) {
            $query->whereHas('order', function ($q) use ($args) {
                $this->applyWhereConditions($q, $args['hasOrder']);
            });
        }

        if (isset($args['orderType']) && is_array($args['orderType'])) {
            $handler = new OrderTransitionHistoryOrderTypeHandler(new SQLOperator());
            $handler($query, $args['orderType'], null, 'and');
        }

        if (isset($args['hasVariant']) && is_array($args['hasVariant'])) {
            $handler = new OrderTransitionHistoryVariantHandler(new SQLOperator());
            $handler($query, $args['hasVariant'], null, 'and');
        }

        if (isset($args['hasProvider']) && is_array($args['hasProvider'])) {
            $handler = new OrderTransitionHistoryProviderHandler(new SQLOperator());
            $handler($query, $args['hasProvider'], null, 'and');
        }

        if (isset($args['paymentMethodType'])) {
            $query->paymentMethodType($args['paymentMethodType']);
        }

        if (isset($args['orderBy']) && is_array($args['orderBy'])) {
            foreach ($args['orderBy'] as $order) {
                if (! is_array($order) || ! isset($order['column'])) {
                    continue;
                }

                $column = $order['column'];
                $direction = $order['order'] ?? 'ASC';

                if (is_string($column)) {
                    $column = strtolower($column);
                }

                $query->orderBy($column, $direction);
            }
        } else {
            $query->orderBy('created_at', 'DESC');
        }

        return $query;
    }

    public function applyWhereConditions(Builder $query, array $conditions = []): Builder
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

        if (isset($conditions['column'], $conditions['value'])) {
            $this->applySingleCondition($query, $conditions, $operatorMap);
        }

        if (isset($conditions['AND']) && is_array($conditions['AND'])) {
            foreach ($conditions['AND'] as $andCondition) {
                if (is_array($andCondition) && isset($andCondition['column'], $andCondition['value'])) {
                    $this->applySingleCondition($query, $andCondition, $operatorMap);
                }
            }
        }

        return $query;
    }

    private function applySingleCondition(Builder $query, array $condition, array $operatorMap): void
    {
        $column = $condition['column'];
        $operator = strtoupper($condition['operator'] ?? 'EQ');
        $value = $condition['value'];

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
