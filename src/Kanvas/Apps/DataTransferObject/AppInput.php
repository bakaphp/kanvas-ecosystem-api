<?php

declare(strict_types=1);

namespace Kanvas\Apps\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * AppsData class.
 */
class AppInput extends Data
{
    /**
     * Construct function.
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $domain,
        public int $is_actived,
        public int $ecosystem_auth,
        public int $payments_active,
        public int $is_public,
        public int $domain_based,
        public ?string $url = null
    ) {
    }
}
