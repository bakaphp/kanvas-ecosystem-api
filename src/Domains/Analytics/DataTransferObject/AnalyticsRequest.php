<?php

declare(strict_types=1);

namespace Kanvas\Analytics\DataTransferObject;

use Carbon\Carbon;
use Illuminate\Support\Facades\Date;
use Kanvas\Analytics\Enums\AnalyticsBucketEnum;
use Kanvas\Companies\Models\Companies;

class AnalyticsRequest
{
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly AnalyticsBucketEnum $bucket,
        public readonly string $timezone,
    ) {
    }

    /**
     * Build from raw GraphQL args. Resolves timezone from:
     * 1) explicit $args['timezone']
     * 2) company->timezone
     * 3) config('app.timezone').
     *
     * Resolves from/to defaults:
     * - `to`   defaults to now()
     * - `from` defaults to now()->subDays(30)
     *
     * @param  array<string, mixed>  $args
     */
    public static function fromGraphQL(array $args, Companies $company): self
    {
        $timezone = (string) ($args['timezone'] ?? '');
        if ($timezone === '') {
            $companyTimezone = (string) ($company->timezone ?? '');
            $timezone = $companyTimezone !== '' ? $companyTimezone : ((string) (config('app.timezone') ?? 'UTC'));
        }

        $to = isset($args['to'])
            ? Date::parse((string) $args['to'], $timezone)->endOfDay()
            : Date::now($timezone)->endOfDay();

        $from = isset($args['from'])
            ? Date::parse((string) $args['from'], $timezone)->startOfDay()
            : Date::now($timezone)->subDays(30)->startOfDay();

        $bucket = isset($args['bucket'])
            ? AnalyticsBucketEnum::from((string) $args['bucket'])
            : AnalyticsBucketEnum::WEEK;

        return new self(
            from: $from,
            to: $to,
            bucket: $bucket,
            timezone: $timezone,
        );
    }
}
