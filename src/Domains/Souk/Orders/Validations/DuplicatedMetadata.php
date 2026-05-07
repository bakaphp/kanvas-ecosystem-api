<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Validations;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Override;

class DuplicatedMetadata implements ValidationRule
{
    public function __construct(
        private AppInterface $app,
        private CompanyInterface $company,
        private ?OrderTypes $orderType = null,
    ) {
    }

    #[Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->app->get(ConfigurationEnum::VALIDATE_METADATA_DUPLICATED_ENABLED->value)) {
            return;
        }

        $settings = $this->resolveSettings($this->orderType?->config ?? []);

        if (! $settings['field']) {
            return;
        }

        $fieldValue = $this->extractFieldValue($value, $settings['field']);

        if (! $fieldValue) {
            return;
        }

        if ($this->isDuplicate($fieldValue, $settings)) {
            $fail("The {$attribute} contains a duplicate value for field '{$settings['field']}'.");
        }
    }

    private function resolveSettings(array $config): array
    {
        $cooldown = $config['validate_metadata_duplicated_cooldown_hours'] ?? null;

        return [
            'field' => $config['validate_metadata_duplicated_field'] ?? null,
            'cooldown_hours' => $cooldown !== null ? (int) $cooldown : null,
            'blocking_statuses' => $config['validate_metadata_duplicated_blocking_statuses'] ?? null,
        ];
    }

    private function isDuplicate(mixed $value, array $settings): bool
    {
        $normalizedValue = is_string($value) ? strtolower($value) : $value;

        $query = Order::fromApp($this->app)
            ->whereNotNull('metadata')
            ->where('metadata', '!=', '')
            ->whereRaw('JSON_VALID(metadata)')
            ->whereRaw('JSON_LENGTH(metadata) > 0')
            ->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.{$settings['field']}'))) = ?", [$normalizedValue]);

        if ($settings['cooldown_hours'] !== null) {
            $query->where('created_at', '>=', Carbon::now()->subHours($settings['cooldown_hours']));
        }

        if ($settings['blocking_statuses'] !== null) {
            $blockingStatuses = array_map('trim', explode(',', $settings['blocking_statuses']));
            $query->whereHas(
                'orderStatus',
                fn ($q) => $q->whereIn('slug', $blockingStatuses)
            );
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
