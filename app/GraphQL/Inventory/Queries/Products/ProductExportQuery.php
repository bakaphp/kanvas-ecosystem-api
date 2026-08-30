<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Queries\Products;

use Exception;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Actions\ExportDynamicProductsAction;
use Kanvas\Inventory\Products\Models\Products;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ProductExportQuery
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
                    'message' => 'No products found matching the specified criteria.',
                ];
            }

            $exportAction = new ExportDynamicProductsAction(
                app: $app,
                user: $user,
                productQuery: $query,
                fieldMapper: $fieldMapper,
                metadata: $metadata,
                params: $args['where'] ?? [],
                timezone: $timezone
            );

            return $exportAction->execute($format);
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'download_url' => null,
                'file_name' => null,
                'message' => 'Export failed: ' . $e->getMessage(),
            ];
        }
    }

    private function buildQuery(Apps $app, Companies $company, array $args): Builder
    {
        $query = Products::query()
            ->fromCompany($company)
            ->fromApp($app)
            ->notDeleted();

        if (! empty($args['search'])) {
            $term = $args['search'];
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%")
                    ->orWhere('slug', 'like', "%{$term}%")
                    ->orWhere('uuid', 'like', "%{$term}%");
            });
        }

        if (isset($args['where']) && is_array($args['where'])) {
            $this->applyWhereConditions($query, $args['where']);
        }

        if (isset($args['hasAttributeValues']) && is_array($args['hasAttributeValues'])) {
            foreach ($args['hasAttributeValues'] as $filter) {
                $query->filterByAttributeValue(...Products::attributeFilterArgsFromInput($filter));
            }
        }

        if (isset($args['hasProductsTypes']) && is_array($args['hasProductsTypes'])) {
            $query->whereHas(
                'type',
                function (Builder $q) use ($args): void {
                    $this->applyWhereConditions($q, $args['hasProductsTypes']);
                }
            );
        }

        if (isset($args['orderBy']) && is_array($args['orderBy'])) {
            foreach ($args['orderBy'] as $order) {
                if (! is_array($order) || ! isset($order['column'])) {
                    continue;
                }
                $query->orderBy(strtolower((string) $order['column']), $order['order'] ?? 'ASC');
            }
        } else {
            $query->orderBy('created_at', 'DESC');
        }

        return $query;
    }

    private function applyWhereConditions(Builder $query, array $conditions): void
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

        $apply = function (Builder $q, array $condition) use ($operatorMap): void {
            $column = strtolower((string) ($condition['column'] ?? ''));
            $operator = strtoupper((string) ($condition['operator'] ?? 'EQ'));
            $value = $condition['value'] ?? null;

            if ($operator === 'BETWEEN' && is_array($value) && count($value) >= 2) {
                $q->whereBetween($column, [$value[0], $value[1]]);
            } elseif ($operator === 'IN') {
                $q->whereIn($column, (array) $value);
            } elseif ($operator === 'NOT_IN') {
                $q->whereNotIn($column, (array) $value);
            } elseif (array_key_exists($operator, $operatorMap)) {
                $q->where($column, $operatorMap[$operator], $value);
            }
        };

        if (isset($conditions['column'], $conditions['value'])) {
            $apply($query, $conditions);
        }

        foreach ($conditions['AND'] ?? [] as $andCondition) {
            if (is_array($andCondition) && isset($andCondition['column'], $andCondition['value'])) {
                $apply($query, $andCondition);
            }
        }
    }
}
