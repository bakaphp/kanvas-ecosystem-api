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
use Override;

class DuplicatedMetadata implements ValidationRule
{
    public function __construct(
        private AppInterface $app,
        private CompanyInterface $company
    ) {
    }

    #[Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Check if validation is enabled for this app
        $enabled = $this->app->get('validate_metadata_duplicated_enabled');
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

        // if ($settings['use_cache']) {
        //     $cacheKey = "souk_unique_{$this->app->id}_{$settings['field']}_{$normalizedValue}";

        //     return Cache::remember($cacheKey, 300, function () use ($normalizedValue, $settings) {
        //         return $this->queryDuplicate($normalizedValue, $settings);
        //     });
        // }

        return $this->queryDuplicate($normalizedValue, $settings);
    }

    private function queryDuplicate(mixed $value, array $settings): bool
    {
        // Convert dot notation to JSON path for whereJsonContains
        $jsonPath = $settings['field'];

        $query = Order::fromApp($this->app)
            ->where('created_at', '>=', Carbon::now()->subHours($settings['cooldown_hours']))
            ->whereNotNull('metadata')
            ->where('metadata', '!=', '')
            ->whereRaw("JSON_VALID(metadata)")
            ->whereRaw("JSON_LENGTH(metadata) > 0")
            ->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.{$jsonPath}'))) = ?", [strtolower($value)]);

        // Exclude certain order statuses if configured
        if (! empty($settings['exclude_statuses'])) {
            $excludeStatuses = array_map('trim', explode(',', $settings['exclude_statuses']));
            $query->whereHas('orderStatus', function ($q) use ($excludeStatuses) {
                $q->whereNotIn('slug', $excludeStatuses);
            });
        }

        return $query->exists();
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
