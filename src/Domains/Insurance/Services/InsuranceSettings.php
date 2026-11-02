<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Insurance\Enums\InsuranceConfigurationEnum;

final class InsuranceSettings
{
    public const array DEFAULT_PAYMENT_PROCESSORS = ['azul'];

    public static function list(AppInterface $app, InsuranceConfigurationEnum $key, array $default): array
    {
        $value = $app->get($key->value);
        $items = is_array($value) ? $value : explode(',', (string) $value);
        $items = array_values(array_filter(array_map(trim(...), array_map('strval', $items)), fn (string $item): bool => $item !== ''));

        return $items === [] ? $default : $items;
    }
}
