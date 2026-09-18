<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Enums\ParkingApplicationFieldEnum;
use Kanvas\Connectors\Movipass\Enums\ParkingApplicationFieldTypeEnum;
use Kanvas\Connectors\Movipass\Enums\ParkingApplicationStepEnum;
use Kanvas\Exceptions\ValidationException;
use Throwable;

class ValidateParkingApplicationStepAction
{
    public function __construct(
        protected readonly array $fields,
        protected readonly ?ParkingApplicationStepEnum $step = null,
    ) {
    }

    public function execute(): array
    {
        $normalized = [];

        foreach ($this->fields as $key => $value) {
            $field = ParkingApplicationFieldEnum::tryFrom((string) $key);

            if ($field === null) {
                throw new ValidationException("Unknown parking application field: {$key}");
            }

            if ($field->step() === null) {
                throw new ValidationException("{$field->value} cannot be written through this validator");
            }

            if ($this->step !== null && $field->step() !== $this->step) {
                throw new ValidationException("{$field->value} does not belong to step {$this->step->value}");
            }

            $normalized[$field->value] = $this->normalizeValue($field, $value);
        }

        $this->assertCapacityBreakdownWithinTotal($normalized);
        $this->assertIs24_7NotContradictedBySchedule($normalized);

        if ($this->step !== null) {
            $this->assertScheduleRequiredWhenNot24_7($normalized);
            $this->assertApplicantTypeIdentityIsComplete($normalized);
        }

        return $normalized;
    }

    private function normalizeValue(ParkingApplicationFieldEnum $field, mixed $value): mixed
    {
        $type = $field->type();

        if ($type === ParkingApplicationFieldTypeEnum::SCHEDULE) {
            return $this->normalizeSchedule($value);
        }

        if ($type === ParkingApplicationFieldTypeEnum::CLOSURES) {
            return $this->normalizeClosures($value);
        }

        if ($type === ParkingApplicationFieldTypeEnum::PAYMENT_METHODS) {
            return $this->normalizePaymentMethods($field, $value);
        }

        if ($value === null) {
            return null;
        }

        $this->assertScalar($value, $field->value);

        return match ($type) {
            ParkingApplicationFieldTypeEnum::STRING,
            ParkingApplicationFieldTypeEnum::TEXT => Str::trimToNull((string) $value),
            ParkingApplicationFieldTypeEnum::INTEGER => $this->normalizeInteger($field, $value),
            ParkingApplicationFieldTypeEnum::DECIMAL => $this->normalizeDecimal($field, $value),
            ParkingApplicationFieldTypeEnum::BOOLEAN => $this->normalizeBoolean($field, $value),
            ParkingApplicationFieldTypeEnum::ENUM => $this->assertOption($field, $value),
            ParkingApplicationFieldTypeEnum::EMAIL => $this->normalizeEmail($field, $value),
            default => throw new ValidationException("{$field->value} has no validator for {$type->value}"),
        };
    }

    private function assertScalar(mixed $value, string $label): void
    {
        if (! is_scalar($value)) {
            throw new ValidationException("{$label} must be a single value, not a list or object");
        }
    }

    private function normalizeInteger(ParkingApplicationFieldEnum $field, mixed $value): int
    {
        if (! is_numeric($value) || (int) $value != $value) {
            throw new ValidationException("{$field->value} must be an integer");
        }

        $integer = (int) $value;

        if ($integer < 0) {
            throw new ValidationException("{$field->value} must not be negative");
        }

        if ($field === ParkingApplicationFieldEnum::CAPACITY_TOTAL && $integer <= 0) {
            throw new ValidationException("{$field->value} must be greater than zero");
        }

        return $integer;
    }

    private function normalizeDecimal(ParkingApplicationFieldEnum $field, mixed $value): float
    {
        if (! is_numeric($value)) {
            throw new ValidationException("{$field->value} must be a decimal number");
        }

        $decimal = (float) $value;

        if (! $this->allowsNegativeDecimal($field) && $decimal < 0) {
            throw new ValidationException("{$field->value} must not be negative");
        }

        $this->assertDecimalWithinRange($field, $decimal);

        return $decimal;
    }

    private function allowsNegativeDecimal(ParkingApplicationFieldEnum $field): bool
    {
        return $field === ParkingApplicationFieldEnum::LATITUDE || $field === ParkingApplicationFieldEnum::LONGITUDE;
    }

    private function assertDecimalWithinRange(ParkingApplicationFieldEnum $field, float $value): void
    {
        $range = match ($field) {
            ParkingApplicationFieldEnum::LATITUDE => [-90.0, 90.0],
            ParkingApplicationFieldEnum::LONGITUDE => [-180.0, 180.0],
            default => null,
        };

        if ($range === null) {
            return;
        }

        [$min, $max] = $range;

        if ($value < $min || $value > $max) {
            throw new ValidationException("{$field->value} must be between {$min} and {$max}");
        }
    }

    private function normalizeBoolean(ParkingApplicationFieldEnum $field, mixed $value): bool
    {
        $normalized = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($normalized === null) {
            throw new ValidationException("{$field->value} must be a boolean");
        }

        return $normalized;
    }

    private function assertOption(ParkingApplicationFieldEnum $field, mixed $value): string
    {
        $options = $field->options() ?? [];
        $normalized = Str::trimToNull((string) $value);

        if ($normalized === null || ! in_array($normalized, $options, true)) {
            throw new ValidationException("{$field->value} must be one of: " . implode(', ', $options));
        }

        return $normalized;
    }

    private function normalizeEmail(ParkingApplicationFieldEnum $field, mixed $value): string
    {
        $email = Str::lowerTrim((string) $value);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException("{$field->value} must be a valid email address");
        }

        return $email;
    }

    private function normalizeSchedule(mixed $value): array
    {
        if (! is_array($value)) {
            throw new ValidationException('parking_application_schedule must be an object');
        }

        $allowedGroups = ['weekdays', 'saturday', 'sunday_holidays'];
        $normalized = [];

        foreach ($value as $group => $bands) {
            if (! in_array($group, $allowedGroups, true)) {
                throw new ValidationException("parking_application_schedule has an unknown group: {$group}");
            }

            if (! is_array($bands)) {
                throw new ValidationException("parking_application_schedule.{$group} must be a list of bands");
            }

            $normalized[$group] = $this->normalizeScheduleGroupBands($group, $bands);
        }

        return $normalized;
    }

    private function normalizeScheduleGroupBands(string $group, array $bands): array
    {
        $normalizedBands = array_map(
            fn (mixed $band): array => $this->normalizeScheduleBand($group, $band),
            $bands,
        );

        $this->assertBandsDoNotOverlap($group, $normalizedBands);

        return $normalizedBands;
    }

    private function normalizeScheduleBand(string $group, mixed $band): array
    {
        if (! is_array($band) || ! isset($band['open'], $band['close'])) {
            throw new ValidationException("parking_application_schedule.{$group} bands need open and close");
        }

        $this->assertScalar($band['open'], "parking_application_schedule.{$group} open");
        $this->assertScalar($band['close'], "parking_application_schedule.{$group} close");

        $open = (string) $band['open'];
        $close = (string) $band['close'];
        $this->assertTime($group, $open);
        $this->assertTime($group, $close);

        if ($open === $close && $open !== '00:00') {
            throw new ValidationException("parking_application_schedule.{$group} has a zero-length band");
        }

        return ['open' => $open, 'close' => $close];
    }

    private function assertTime(string $group, string $time): void
    {
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new ValidationException("parking_application_schedule.{$group} has an invalid time: {$time}");
        }
    }

    private function assertBandsDoNotOverlap(string $group, array $bands): void
    {
        $occupiedMinutes = [];

        foreach ($bands as $band) {
            [$start, $end] = $this->bandToMinuteRange($band);

            for ($minute = $start; $minute < $end; ++$minute) {
                $minuteOfDay = $minute % 1440;

                if (isset($occupiedMinutes[$minuteOfDay])) {
                    throw new ValidationException("parking_application_schedule.{$group} has overlapping bands");
                }

                $occupiedMinutes[$minuteOfDay] = true;
            }
        }
    }

    private function bandToMinuteRange(array $band): array
    {
        $start = $this->timeToMinutes($band['open']);
        $end = $this->timeToMinutes($band['close']);

        return [$start, $end > $start ? $end : $end + 1440];
    }

    private function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    private function normalizeClosures(mixed $value): array
    {
        if (! is_array($value)) {
            throw new ValidationException('parking_application_closures must be a list');
        }

        return array_map(fn (mixed $closure): array => $this->normalizeClosure($closure), $value);
    }

    private function normalizeClosure(mixed $closure): array
    {
        if (! is_array($closure) || ! isset($closure['type'])) {
            throw new ValidationException('parking_application_closures items need a type');
        }

        $this->assertScalar($closure['type'], 'parking_application_closures type');

        return match ($closure['type']) {
            'recurring' => $this->normalizeRecurringClosure($closure),
            'one_off' => $this->normalizeOneOffClosure($closure),
            default => throw new ValidationException("parking_application_closures has an unknown type: {$closure['type']}"),
        };
    }

    private function normalizeRecurringClosure(array $closure): array
    {
        $weekday = $closure['weekday'] ?? null;
        $isValidWeekday = is_numeric($weekday) && (int) $weekday == $weekday && (int) $weekday >= 0 && (int) $weekday <= 6;

        if (! $isValidWeekday) {
            throw new ValidationException('parking_application_closures recurring entries need an integer weekday between 0 and 6');
        }

        return ['type' => 'recurring', 'weekday' => (int) $weekday];
    }

    private function normalizeOneOffClosure(array $closure): array
    {
        if (isset($closure['date'])) {
            $this->assertScalar($closure['date'], 'parking_application_closures date');
        }

        $date = (string) ($closure['date'] ?? '');

        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $date);
        } catch (Throwable) {
            $parsed = false;
        }

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new ValidationException('parking_application_closures one_off entries need a valid YYYY-MM-DD date');
        }

        $normalized = ['type' => 'one_off', 'date' => $date];

        if (isset($closure['reason'])) {
            $this->assertScalar($closure['reason'], 'parking_application_closures reason');

            $reason = Str::trimToNull((string) $closure['reason']);

            if ($reason !== null) {
                $normalized['reason'] = $reason;
            }
        }

        return $normalized;
    }

    private function normalizePaymentMethods(ParkingApplicationFieldEnum $field, mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            throw new ValidationException("{$field->value} must be a non-empty list");
        }

        $methods = [];

        foreach ($value as $method) {
            $this->assertScalar($method, "{$field->value} entry");
            $method = $this->assertOption($field, $method);
            $methods[$method] = $method;
        }

        if (! isset($methods['movipass'])) {
            throw new ValidationException("{$field->value} must include movipass");
        }

        return array_values($methods);
    }

    private function assertCapacityBreakdownWithinTotal(array $normalized): void
    {
        if (! array_key_exists(ParkingApplicationFieldEnum::CAPACITY_TOTAL->value, $normalized)) {
            return;
        }

        $total = $normalized[ParkingApplicationFieldEnum::CAPACITY_TOTAL->value];

        $breakdownKeys = [
            ParkingApplicationFieldEnum::CAPACITY_LIGHT_VEHICLES,
            ParkingApplicationFieldEnum::CAPACITY_MOTORCYCLES,
            ParkingApplicationFieldEnum::CAPACITY_DISABILITY,
        ];

        $breakdown = 0;

        foreach ($breakdownKeys as $key) {
            $breakdown += $normalized[$key->value] ?? 0;
        }

        if ($breakdown > $total) {
            throw new ValidationException('parking_application_capacity breakdown must not exceed the total');
        }
    }

    private function scheduleHasAtLeastOneBand(array $normalized): bool
    {
        $schedule = $normalized[ParkingApplicationFieldEnum::SCHEDULE->value] ?? null;

        if (! is_array($schedule)) {
            return false;
        }

        foreach ($schedule as $bands) {
            if (! empty($bands)) {
                return true;
            }
        }

        return false;
    }

    private function assertIs24_7NotContradictedBySchedule(array $normalized): void
    {
        $is24_7 = $normalized[ParkingApplicationFieldEnum::IS_24_7->value] ?? null;

        if ($is24_7 !== true) {
            return;
        }

        if ($this->scheduleHasAtLeastOneBand($normalized)) {
            throw new ValidationException('parking_application_is_24_7 cannot be true alongside a schedule');
        }
    }

    private function assertScheduleRequiredWhenNot24_7(array $normalized): void
    {
        $is24_7 = $normalized[ParkingApplicationFieldEnum::IS_24_7->value] ?? null;

        if ($is24_7 !== false) {
            return;
        }

        if (! $this->scheduleHasAtLeastOneBand($normalized)) {
            throw new ValidationException('parking_application_is_24_7 is false but no schedule was given');
        }
    }

    private function assertApplicantTypeIdentityIsComplete(array $normalized): void
    {
        $applicantType = $normalized[ParkingApplicationFieldEnum::APPLICANT_TYPE->value] ?? null;

        if ($applicantType === null) {
            return;
        }

        if ($applicantType === 'juridica') {
            $this->assertPresent($normalized, ParkingApplicationFieldEnum::RNC);
            $this->assertPresent($normalized, ParkingApplicationFieldEnum::LEGAL_NAME);
        }

        if ($applicantType === 'natural') {
            $this->assertPresent($normalized, ParkingApplicationFieldEnum::NATIONAL_ID);
            $this->assertPresent($normalized, ParkingApplicationFieldEnum::FULL_NAME);
        }
    }

    private function assertPresent(array $normalized, ParkingApplicationFieldEnum $field): void
    {
        if (! array_key_exists($field->value, $normalized) || $normalized[$field->value] === null) {
            throw new ValidationException("{$field->value} is required for this applicant type");
        }
    }
}
