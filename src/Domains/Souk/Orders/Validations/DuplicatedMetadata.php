<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Validations;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Souk\Orders\Models\Order;

class DuplicatedMetadata implements ValidationRule
{
    public function __construct(
        private AppInterface $app,
        private CompanyInterface $company
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Check if validation is enabled for this app
        $enabled = $this->app->get("validate_metadata_duplicated_enabled");
        if (! $enabled || $enabled !== 1) {
            return;
        }

        // Get configuration from individual app settings
        $settings = $this->getConfigFromAppSettings();

        // Extract the specific field value from metadata
        $fieldValue = $this->extractFieldValue($value, $settings['field']);

        if (! $fieldValue) {
            return; // No field value to check
        }

        // Check if this is a duplicate
        if ($this->isDuplicate($fieldValue, $settings)) {
            $fail("The {$attribute} contains a duplicate value for field '{$settings['field']}'.");
        }
    }

    private function getConfigFromAppSettings(): array
    {
        return [
            'field' => $this->app->get('validate_metadata_duplicated_field', 'data.tracking_id'),
            'cooldown_hours' => (int) $this->app->get('validate_metadata_duplicated_cooldown_hours', 24),
            'use_cache' => (bool) $this->app->get('validate_metadata_duplicated_use_cache', false),
            'exclude_statuses' => $this->app->get('validate_metadata_duplicated_exclude_statuses', ''),
        ];
    }

    private function isDuplicate(mixed $value, array $settings): bool
    {
        // Normalize to lowercase for case-insensitive comparison
        $normalizedValue = is_string($value) ? strtolower($value) : $value;

        return $this->queryDuplicate($normalizedValue, $settings);
    }

    private function queryDuplicate(mixed $value, array $settings): bool
    {
        $jsonPath = $settings['field'];

        // Base query for matching metadata field
        $baseQuery = Order::fromApp($this->app)
            ->whereNotNull('metadata')
            ->where('metadata', '!=', '')
            ->whereRaw("JSON_VALID(metadata) = 1")
            ->whereRaw("JSON_EXTRACT(metadata, '$.{$jsonPath}') IS NOT NULL")
            ->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.{$jsonPath}'))) = ?", [strtolower($value)]);

        // If no exclude statuses configured, just check within cooldown period
        if (empty($settings['exclude_statuses'])) {
            return $baseQuery
                ->where('created_at', '>=', Carbon::now()->subHours($settings['cooldown_hours']))
                ->exists();
        }

        $excludeStatuses = array_map('trim', explode(',', $settings['exclude_statuses']));

        // Check two conditions:
        return $baseQuery->where(function ($query) use ($excludeStatuses, $settings) {
            // Condition 1: Within cooldown AND NOT in excluded statuses
            $query->where(function ($q) use ($excludeStatuses, $settings) {
                $q->where('created_at', '>=', Carbon::now()->subHours($settings['cooldown_hours']))
                    ->whereHas('orderStatus', function ($statusQuery) use ($excludeStatuses) {
                        $statusQuery->whereNotIn('slug', $excludeStatuses);
                    });
            })
            // Condition 2: OR in excluded statuses (any time)
            ->orWhereHas('orderStatus', function ($statusQuery) use ($excludeStatuses) {
                $statusQuery->whereIn('slug', $excludeStatuses);
            });
        })->exists();
    }

    private function extractFieldValue(mixed $metadata, string $fieldPath): mixed
    {
        $parts = explode('.', $fieldPath);
        $value = $metadata;

        foreach ($parts as $part) {
            if (! is_array($value) || ! isset($value[$part])) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }
}
