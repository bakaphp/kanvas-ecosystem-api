<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Kanvas\Guild\Customers\DataTransferObject\DriverLicense;
use Kanvas\Guild\Customers\Models\People;

/**
 * Fills the license columns without touching names, dob or addresses.
 *
 * Blank columns are always filled; populated ones are left alone unless `$overwrite` is set,
 * so a self-reported credit-app license never clobbers a scan.
 *
 * `$quietly` is for backfills: it fires no workflow and no model events. Old data replayed
 * through the workflow engine would run lead automations years after the fact, and the People
 * observer broadcasts `people.updated` on every save.
 */
class UpdatePeopleDriverLicenseAction
{
    public function __construct(
        private readonly People $people,
        private readonly DriverLicense $license,
        private readonly bool $overwrite = false,
        private readonly bool $quietly = false,
    ) {
    }

    public function execute(): bool
    {
        if (! $this->apply()) {
            return false;
        }

        if ($this->quietly) {
            $this->people->disableWorkflows();
            $this->people->saveQuietly();

            return true;
        }

        $this->people->saveOrFail();

        return true;
    }

    /**
     * Applies the change in memory only and reports the columns that would be written.
     *
     * @return array<int, string>
     */
    public function preview(): array
    {
        return $this->apply() ? array_keys($this->people->getDirty()) : [];
    }

    private function apply(): bool
    {
        $existingNumber = (string) ($this->people->license_number ?? '');

        if ($this->overwrite || $existingNumber === '') {
            $this->people->license_number = $this->license->number;
        } elseif (strcasecmp($existingNumber, $this->license->number) !== 0) {
            // A different license is on file. Grafting this one's state/expiry onto it would
            // produce a row describing two documents at once.
            return false;
        }

        $this->fill('license_expiration_date', $this->license->expirationDate);
        $this->fill('license_state', $this->license->state);

        return $this->people->isDirty();
    }

    private function fill(string $column, mixed $value): void
    {
        if ($value !== null && ($this->overwrite || empty($this->people->{$column}))) {
            $this->people->{$column} = $value;
        }
    }
}
