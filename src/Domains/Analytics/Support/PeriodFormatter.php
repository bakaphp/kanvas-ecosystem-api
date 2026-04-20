<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Support;

use Kanvas\Analytics\Enums\AnalyticsBucketEnum;

class PeriodFormatter
{
    /**
     * Build the SQL fragment that buckets a timestamp column by the given bucket in the given timezone,
     * plus the bindings that must be passed to the query method (selectRaw / groupByRaw / orderByRaw).
     *
     * Period key format:
     *  - DAY:   "YYYY-MM-DD"
     *  - WEEK:  "YYYY-Www" (ISO week, using MySQL's %x-W%v)
     *  - MONTH: "YYYY-MM"
     *
     * @return array{sql: string, bindings: array<int, string>}
     */
    public static function bucketExpression(
        string $column,
        AnalyticsBucketEnum $bucket,
        string $timezone,
    ): array {
        $quoted = implode('.', array_map(
            static fn (string $part): string => '`' . str_replace('`', '', $part) . '`',
            explode('.', $column),
        ));
        $tzFragment = "CONVERT_TZ({$quoted}, '+00:00', ?)";

        $sql = match ($bucket) {
            AnalyticsBucketEnum::DAY => "DATE_FORMAT({$tzFragment}, '%Y-%m-%d')",
            AnalyticsBucketEnum::WEEK => "DATE_FORMAT({$tzFragment}, '%x-W%v')",
            AnalyticsBucketEnum::MONTH => "DATE_FORMAT({$tzFragment}, '%Y-%m')",
        };

        return [
            'sql' => $sql,
            'bindings' => [$timezone],
        ];
    }
}
