<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries\Taxes;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;

class TaxSettingsQuery
{
    use ResolvesActingContext;

    public function resolve(mixed $rootValue, array $request): array
    {
        $app = $this->actingContext()->app;

        return [
            'enabled' => (bool) $app->get(ConfigurationEnum::TAX_ENGINE_ENABLED->value),
            'fail_open' => (bool) $app->get(ConfigurationEnum::TAX_FAIL_OPEN->value),
        ];
    }
}
