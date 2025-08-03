<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Filters;

use Illuminate\Database\Eloquent\Builder;

class OrderQueryFilter
{
    private const ALLOWED_COLUMNS = [
        'status',
        'order_type_id',
        'user_id',
        'total',
        'created_at',
        'updated_at',
        'reference',
        'order_number',
        'user_email',
        'user_phone'
    ];

    private const OPERATOR_MAP = [
        'EQ' => '=',
        'NEQ' => '!=',
        'GT' => '>',
        'GTE' => '>=',
        'LT' => '<',
        'LTE' => '<=',
        'LIKE' => 'like',
    ];

    public static function apply(Builder $query, array $conditions): Builder
    {
        foreach ($conditions as $condition) {
            self::applyCondition($query, $condition);
        }

        return $query;
    }

    private static function applyCondition(Builder $query, array $condition): void
    {
        $column = $condition['column'];
        $operator = $condition['operator'] ?? 'EQ';
        $value = $condition['value'];

        // Validate column name to prevent SQL injection
        if (!in_array($column, self::ALLOWED_COLUMNS)) {
            throw new \InvalidArgumentException("Invalid column: {$column}");
        }

        match ($operator) {
            'EQ', 'NEQ', 'GT', 'GTE', 'LT', 'LTE' => 
                $query->where($column, self::OPERATOR_MAP[$operator], $value),
            
            'LIKE' => 
                $query->where($column, 'like', "%{$value}%"),
            
            'IN' => 
                $query->whereIn($column, is_array($value) ? $value : [$value]),
            
            'NOT_IN' => 
                $query->whereNotIn($column, is_array($value) ? $value : [$value]),
            
            default => 
                throw new \InvalidArgumentException("Unsupported operator: {$operator}")
        };
    }
}