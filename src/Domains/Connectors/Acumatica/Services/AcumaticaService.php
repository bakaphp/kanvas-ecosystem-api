<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Services;

use Kanvas\Connectors\Acumatica\DataTransferObject\Acumatica as AcumaticaDto;
use Kanvas\Connectors\Acumatica\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class AcumaticaService
{
    /**
     * Store the Acumatica connection config on the app (app-level, single array key).
     * One config feeds both transports: REST (write-back) + SQL replica (reads).
     */
    public static function setup(AcumaticaDto $data): bool
    {
        $config = $data->toConfig();

        $required = ['baseUrl', 'username', 'password', 'acumaticaCompany'];
        $missing = array_values(array_filter($required, fn (string $key) => empty($config[$key])));

        if (! empty($missing)) {
            throw new ValidationException(
                'Acumatica configuration is missing the following keys: ' . implode(', ', $missing)
            );
        }

        return $data->app->set(ConfigurationEnum::ACUMATICA_CONFIG->value, $config);
    }
}
