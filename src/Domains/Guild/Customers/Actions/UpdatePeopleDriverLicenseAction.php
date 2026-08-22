<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Kanvas\Guild\Customers\DataTransferObject\DriverLicense;
use Kanvas\Guild\Customers\Models\People;

/**
 * For self-reported sources (credit-app forms) — writes only the license, no names or address.
 * A scan from `DriverLicenseVerificationService` always wins, so an existing `license_number`
 * is replaced only when `$overwrite` is set.
 */
class UpdatePeopleDriverLicenseAction
{
    public function __construct(
        private readonly People $people,
        private readonly DriverLicense $license,
        private readonly bool $overwrite = false,
    ) {
    }

    public function execute(): People
    {
        if (! $this->overwrite && ! empty($this->people->license_number)) {
            return $this->people;
        }

        $this->people->license_number = $this->license->number;

        if ($this->license->expirationDate !== null) {
            $this->people->license_expiration_date = $this->license->expirationDate;
        }

        if ($this->license->state !== null) {
            $this->people->license_state = $this->license->state;
        }

        $this->people->saveOrFail();

        return $this->people;
    }
}
