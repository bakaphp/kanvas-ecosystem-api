<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\DataTransferObject;

use Carbon\Carbon;
use Spatie\LaravelData\Data;

/**
 * Scanners (Intellicheck, OCR, Mindee) each emit their own array; all of them normalize here
 * so the People row — not a per-connector custom field — is what third parties are built from.
 */
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

    /**
     * Null when the scan carries no license number — it is unusable downstream and must not
     * overwrite what the People row already holds.
     */
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
     * Every consumer (DriveCentric, VinSolution, 700Credit) wants the 2-letter code, and
     * sources hand it over as a bare string or a `{code: 'FL'}` array. A full state name is
     * rejected rather than stored — a caller that has one resolves it against `States` first.
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
     * The `{driversLicenseNumber, driversLicenseState}` shape DriveCentric ships verbatim
     * as its `drivingLicense` payload — do not add keys to it.
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

    /** Scanners zero-fill `['year' =>, 'month' =>, 'day' =>]` when a date could not be read. */
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
