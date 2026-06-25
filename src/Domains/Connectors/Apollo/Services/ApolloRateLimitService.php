<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Services;

use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Apollo\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;

class ApolloRateLimitService
{
    public const int DEFAULT_DAILY_LIMIT = 2000;

    private const string DEFAULT_REVALIDATION = '-2 months';

    public function hasReachedDailyLimit(CompanyInterface $company, int $dailyLimit = self::DEFAULT_DAILY_LIMIT): bool
    {
        return $this->dailyTotal($company) >= $dailyLimit;
    }

    public function dailyTotal(CompanyInterface $company): int
    {
        $report = (array) ($company->get(ConfigurationEnum::APOLLO_COMPANY_REPORTS->value) ?? []);
        $today = $report[date('Y-m-d')] ?? [];

        return (int) ((is_array($today) ? $today['total'] : null) ?? 0);
    }

    public function hasBeenScreenedRecently(People $people): bool
    {
        $lastScreenedAt = $people->get(ConfigurationEnum::APOLLO_DATA_ENRICHMENT_CUSTOM_FIELDS->value);
        if (empty($lastScreenedAt)) {
            return false;
        }

        $threshold = $people->company->get(ConfigurationEnum::APOLLO_REVALIDATION->value) ?? self::DEFAULT_REVALIDATION;

        return (int) $lastScreenedAt > strtotime($threshold);
    }
}
