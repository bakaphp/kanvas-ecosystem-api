<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Support;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Notifications\ApiUsageLimitNotification;
use Kanvas\Services\DailyReportService;
use Throwable;

class ApiCallTracker
{
    private const string METRIC_TOTAL = 'elead_api_calls';
    private const string METRIC_ERRORS = 'elead_api_errors';
    private const string METRIC_PREFIX = 'elead_api_';

    public function __construct(
        protected AppInterface $app,
        protected Companies $company
    ) {
    }

    public function trackCall(string $method, string $path): int
    {
        $totalCount = DailyReportService::track($this->app, $this->company, self::METRIC_TOTAL);
        DailyReportService::track($this->app, $this->company, self::METRIC_PREFIX . strtolower($method));

        $category = $this->categorizeEndpoint($path);
        DailyReportService::track($this->app, $this->company, self::METRIC_PREFIX . $category);

        $this->checkUsageLimits($totalCount);

        return $totalCount;
    }

    public function trackError(int $statusCode = 0): void
    {
        DailyReportService::track($this->app, $this->company, self::METRIC_ERRORS);

        if ($statusCode > 0) {
            DailyReportService::track($this->app, $this->company, self::METRIC_PREFIX . 'error_' . $statusCode);
        }
    }

    public function getDailyCount(): int
    {
        return DailyReportService::get($this->app, $this->company, self::METRIC_TOTAL);
    }

    public function getDailyErrors(): int
    {
        return DailyReportService::get($this->app, $this->company, self::METRIC_ERRORS);
    }

    public function getDailyLimit(): int
    {
        return (int) ($this->app->get(ConfigurationEnum::ELEAD_DAILY_LIMIT->value) ?? 15000);
    }

    protected function categorizeEndpoint(string $path): string
    {
        if (str_contains($path, '/opportunities')) {
            return 'opportunities';
        }

        if (str_contains($path, '/activities') || str_contains($path, '/activity-history')) {
            return 'activities';
        }

        if (str_contains($path, '/customers')) {
            return 'customers';
        }

        if (str_contains($path, '/productreferencedata')) {
            return 'referencedata';
        }

        return 'other';
    }

    protected function checkUsageLimits(int $currentCount): void
    {
        $dailyLimit = $this->getDailyLimit();

        if ($dailyLimit <= 0) {
            return;
        }

        $usagePercentage = (float) $currentCount / (float) $dailyLimit;
        $alertThresholdValue = $this->app->get(ConfigurationEnum::ELEAD_ALERT_THRESHOLD->value);
        $alertThreshold = (float) ($alertThresholdValue ?? 0.80);
        $alertPoints = [$alertThreshold, 0.90, 1.0];

        foreach ($alertPoints as $point) {
            if ($usagePercentage >= $point) {
                $alertSentKey = 'elead_alert_sent_' . $this->company->getId() . '_' . (int) ($point * 100.0);
                $alreadySent = DailyReportService::get($this->app, $this->company, $alertSentKey);

                if (! $alreadySent) {
                    DailyReportService::track($this->app, $this->company, $alertSentKey);
                    $this->dispatchAlert($currentCount, $dailyLimit, (int) ($point * 100.0));
                }
            }
        }
    }

    protected function dispatchAlert(int $currentCount, int $dailyLimit, int $thresholdPercentage): void
    {
        try {
            $slackWebhook = (string) ($this->app->get(ConfigurationEnum::ELEAD_SLACK_CHANNEL->value) ?? '');

            if (empty($slackWebhook)) {
                Log::warning("Elead API usage at {$thresholdPercentage}% for company {$this->company->getId()} but no Slack channel configured");

                return;
            }

            $notification = new ApiUsageLimitNotification(
                company: $this->company,
                app: $this->app,
                currentCount: $currentCount,
                dailyLimit: $dailyLimit,
                thresholdPercentage: $thresholdPercentage,
            );

            Notification::route('slack', $slackWebhook)->notify($notification);
        } catch (Throwable $e) {
            Log::warning('Failed to send Elead API usage alert: ' . $e->getMessage());
        }
    }
}
