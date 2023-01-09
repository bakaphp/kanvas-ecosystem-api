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
     *
     * @param string $name
     * @param string $url
     * @param string $description
     * @param string $domain
     * @param int $is_actived
     * @param int $ecosystem_auth
     * @param int $payments_active
     * @param int $is_public
     * @param int $domain_based
     */
    public function __construct(
        public string $name,
        public ?string $url = null,
        public string $description,
        public string $domain,
        public int $is_actived,
        public int $ecosystem_auth,
        public int $payments_active,
        public int $is_public,
        public int $domain_based
    ) {
    }
}
