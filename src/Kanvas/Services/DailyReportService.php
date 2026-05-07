<?php

declare(strict_types=1);

namespace Kanvas\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Redis;

class DailyReportService
{
    private const string HASH_PREFIX = 'kanvas:daily:';
    private const int TTL_SECONDS = 172800;

    public static function track(
        AppInterface $app,
        CompanyInterface $company,
        string $key,
        int $count = 1
    ): int {
        $hashKey = self::getHashKey();
        $field = self::buildField($app->getId(), $company->getId(), $key);

        // HINCRBY is atomic and creates field if it doesn't exist
        $newValue = Redis::hincrby($hashKey, $field, $count);

        // Set TTL on first write (if hash is new)
        if ($newValue === $count) {
            Redis::expire($hashKey, self::TTL_SECONDS);
        }

        return $newValue;
    }

    public static function get(
        AppInterface $app,
        CompanyInterface $company,
        string $key,
        ?string $date = null
    ): int {
        $hashKey = self::getHashKey($date);
        $field = self::buildField($app->getId(), $company->getId(), $key);

        return (int) Redis::hget($hashKey, $field) ?: 0;
    }

    public static function getAllMetrics(?string $date = null): array
    {
        $hashKey = self::getHashKey($date);
        $allData = Redis::hgetall($hashKey);

        if (empty($allData)) {
            return [];
        }

        $metrics = [];

        foreach ($allData as $field => $value) {
            // Parse field: "appId:companyId:key"
            $parts = explode(':', $field, 3);

            if (count($parts) !== 3) {
                continue;
            }

            [$appId, $companyId, $key] = $parts;

            if (! isset($metrics[$appId])) {
                $metrics[$appId] = [];
            }

            if (! isset($metrics[$appId][$companyId])) {
                $metrics[$appId][$companyId] = [];
            }

            $metrics[$appId][$companyId][$key] = (int) $value;
        }

        return $metrics;
    }

    /**
     * Get metrics grouped by key with totals
     *
     * @return array{by_app: array, totals: array<string, int>}
     */
    public static function getMetricsWithTotals(?string $date = null): array
    {
        $metrics = self::getAllMetrics($date);
        $totals = [];

        foreach ($metrics as $appId => $companies) {
            foreach ($companies as $companyId => $keys) {
                foreach ($keys as $key => $value) {
                    if (! isset($totals[$key])) {
                        $totals[$key] = 0;
                    }
                    $totals[$key] += $value;
                }
            }
        }

        return [
            'by_app' => $metrics,
            'totals' => $totals,
        ];
    }

    public static function cleanup(?string $date = null): int
    {
        $date = $date ?? Carbon::yesterday()->format('Y-m-d');
        $hashKey = self::getHashKey($date);

        // Get count before deletion for logging
        $fieldCount = Redis::hlen($hashKey);

        // Delete entire hash in one operation
        Redis::del($hashKey);

        return $fieldCount;
    }

    private static function getHashKey(?string $date = null): string
    {
        $date = $date ?? Carbon::now()->format('Y-m-d');

        return self::HASH_PREFIX . $date;
    }

    private static function buildField(int $appId, int $companyId, string $key): string
    {
        return "{$appId}:{$companyId}:{$key}";
    }

    public static function formatReport(?string $date = null): string
    {
        $date = $date ?? Carbon::now()->format('Y-m-d');
        $data = self::getMetricsWithTotals($date);

        if (empty($data['totals'])) {
            return "📊 Kanvas Daily Report - {$date}\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\nNo metrics recorded for this day.";
        }

        $output = "📊 Kanvas Daily Report - {$date}\n";
        $output .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

        foreach ($data['by_app'] as $appId => $companies) {
            foreach ($companies as $companyId => $keys) {
                $output .= "App ID: {$appId} | Company ID: {$companyId}\n";
                foreach ($keys as $key => $value) {
                    $output .= "  • {$key}: {$value}\n";
                }
                $output .= "\n";
            }
        }

        $output .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $output .= "TOTALS:\n";

        foreach ($data['totals'] as $key => $total) {
            $output .= "  • {$key}: {$total}\n";
        }

        return $output;
    }
}
