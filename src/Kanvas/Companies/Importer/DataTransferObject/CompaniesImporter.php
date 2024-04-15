<?php

declare(strict_types=1);

namespace Kanvas\Companies\Importer\DataTransferObject;

use Kanvas\Exceptions\ValidationException;
use Spatie\LaravelData\Data;

class CompaniesImporter extends Data
{
    /**
     * Construct function.
     *
     * @param string $name
     * @param int|null $users_id
     */
    public function __construct(
        public string $name,
        public int $users_id,
        public ?string $email = null,
        public ?string $phone = null,
        public ?int $currency_id = null,
        public ?string $website = null,
        public ?string $address = null,
        public ?int $zipcode = null,
        public ?string $language = null,
        public ?string $timezone = null,
        public ?string $country_code = null,
    ) {
    }
}
