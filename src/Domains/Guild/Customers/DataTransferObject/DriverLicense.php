<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\DataTransferObject;

use Carbon\Carbon;
use Spatie\LaravelData\Data;

/** Every scanner's own array (Intellicheck, OCR, Mindee) normalizes here before it hits People. */
class DriverLicense extends Data
{
    public function __construct(
        public readonly string $number,
        public readonly ?string $state = null,
        public readonly ?Carbon $expirationDate = null,
        public readonly ?Carbon $dob = null,
        public readonly ?string $firstname = null,
        public readonly ?string $middlename = null,
        public readonly ?string $lastname = null,
        public readonly ?string $address = null,
    ) {
    }

    public static function fromScan(?array $scan): ?self
    {
        if ($scan === null || $scan === [] || empty($scan['license'])) {
            return null;
        }

        return new self(
            number: (string) $scan['license'],
            state: self::normalizeState($scan['state'] ?? null),
            expirationDate: self::partsToDate($scan['exp_date'] ?? $scan['expDate'] ?? null),
            dob: self::partsToDate($scan['birthday'] ?? null),
            firstname: ! empty($scan['firstname']) ? (string) $scan['firstname'] : null,
            middlename: ! empty($scan['middlename']) ? (string) $scan['middlename'] : null,
            lastname: ! empty($scan['lastname']) ? (string) $scan['lastname'] : null,
            address: ! empty($scan['address']) ? (string) $scan['address'] : null,
        );
    }

    /**
     * Sources send a bare string or a `{code: 'FL'}` array. A full state name is rejected, not
     * stored — a caller holding one resolves it against `States` first.
     */
    public static function normalizeState(mixed $state): ?string
    {
        if (is_array($state)) {
            $state = $state['code'] ?? null;
        }

        $state = is_string($state) ? strtoupper(trim($state)) : '';

        return strlen($state) === 2 ? $state : null;
    }

    public function isExpired(?Carbon $now = null): bool
    {
        return $this->expirationDate !== null
            && $this->expirationDate->lt($now ?? Carbon::now());
    }

    /**
     * DriveCentric ships this verbatim as its `drivingLicense` payload — do not add keys.
     *
     * @return array{driversLicenseNumber: string, driversLicenseState: string|null}
     */
    public function toDriveCentricArray(): array
    {
        return [
            'driversLicenseNumber' => $this->number,
            'driversLicenseState' => $this->state,
        ];
    }

    private static function partsToDate(mixed $parts): ?Carbon
    {
        if (! is_array($parts)) {
            return null;
        }

        $year = (int) ($parts['year'] ?? 0);
        $month = (int) ($parts['month'] ?? 0);
        $day = (int) ($parts['day'] ?? 0);

        if ($year <= 0 || $month <= 0 || $day <= 0) {
            return null;
        }

        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $parsed = Carbon::createFromFormat('Y-m-d', $date);

        return $parsed && $parsed->format('Y-m-d') === $date ? $parsed->startOfDay() : null;
    }
}
