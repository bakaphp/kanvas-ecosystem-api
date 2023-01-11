<?php

declare(strict_types=1);

namespace Kanvas\Apps\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * AppsData class.
 */
class AppSettingsInput extends Data
{
    /**
     * App settings input
     *
     * @param string $name
     * @param string|null $value
     */
    public function __construct(
        public string $name,
        public string|array $value
    ) {
    }
}
