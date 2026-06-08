<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Queries;

use App\GraphQL\Connector\Movipass\Builders\MechanicsBuilder;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\ExportMechanicsAction;

class ExportMechanicsQuery
{
    public function export(mixed $rootValue, array $args): array
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $query = new MechanicsBuilder()->build($rootValue, $args);
        $this->applyWhereConditions($query, $args['where'] ?? []);
        $this->applyOrderBy($query, $args);

        if ($query->count() === 0) {
            return [
                'status' => 'warning',
                'download_url' => null,
                'file_name' => null,
                'message' => 'No mechanics found matching the specified criteria.',
            ];
        }

        return new ExportMechanicsAction(
            app: $app,
            user: $user,
            mechanics: $query,
            fieldMapper: $args['field_mapper'] ?? null,
            metadata: $args['metadata'] ?? [],
            timezone: $args['timezone'] ?? $user->timezone ?? null,
        )->execute($args['format']);
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

        $apply = function (array $condition) use ($query, $operatorMap): void {
            if (! isset($condition['column'], $condition['value'])) {
                return;
            }

            // Columns come pre-qualified (e.g. "users.firstname") via MechanicWhereColumn.
            $column = (string) $condition['column'];
            $operator = strtoupper($condition['operator'] ?? 'EQ');
            $value = $condition['value'];

            if ($operator === 'BETWEEN' && is_array($value) && count($value) >= 2) {
                $query->whereBetween($column, [$value[0], $value[1]]);
            } elseif (in_array($operator, ['IN', 'NOT_IN'], true)) {
                $query->{$operator === 'IN' ? 'whereIn' : 'whereNotIn'}($column, (array) $value);
            } elseif (array_key_exists($operator, $operatorMap)) {
                $query->where($column, $operatorMap[$operator], $value);
            }
        };

        $apply($conditions);

        foreach ($conditions['AND'] ?? [] as $andCondition) {
            if (is_array($andCondition)) {
                $apply($andCondition);
            }
        }
    }

    private function applyOrderBy(Builder $query, array $args): void
    {
        if (! isset($args['orderBy']) || ! is_array($args['orderBy'])) {
            // Qualify with users.* — the builder joins several tables sharing an `id` column.
            $query->orderBy('users.id', 'ASC');

            return;
        }

        foreach ($args['orderBy'] as $order) {
            if (! is_array($order) || ! isset($order['column'])) {
                continue;
            }

            $column = (string) $order['column'];
            $column = str_contains($column, '.') ? $column : 'users.' . $column;
            $query->orderBy($column, $order['order'] ?? 'ASC');
        }
    }
}
