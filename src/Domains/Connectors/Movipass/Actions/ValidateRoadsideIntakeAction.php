<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Enums\RoadsideIntakeFieldTypeEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideServiceTypeEnum;
use Kanvas\Connectors\Movipass\Support\RoadsideIntakeField;
use Kanvas\Connectors\Movipass\Support\RoadsideIntakeQuestionnaire;
use Kanvas\Exceptions\ValidationException;
use Throwable;

class ValidateRoadsideIntakeAction
{
    private const TRUTHY = ['1', 'true', 'yes', 'si', 'sí', 'y', 's'];
    private const FALSY = ['0', 'false', 'no', 'n'];

    public function __construct(
        private readonly RoadsideServiceTypeEnum $service,
        private readonly array $answers,
    ) {
    }

    /**
     * @return array<string, mixed> the answers cast to their declared types
     */
    public function execute(): array
    {
        $fields = RoadsideIntakeQuestionnaire::keyedFor($this->service);

        // Unknown keys pass through untouched: an operator script gains questions faster than this
        // enum does, and dropping an answer we did not recognise loses case detail for good.
        $normalized = array_diff_key($this->answers, $fields);
        $missing = [];
        $invalid = [];

        foreach ($fields as $key => $field) {
            if (! $this->isAnswered($key)) {
                if ($this->isRequired($field)) {
                    $missing[] = $field->label;
                }

                continue;
            }

            try {
                $normalized[$key] = $this->cast($field, $this->answers[$key]);
            } catch (ValidationException $exception) {
                $invalid[] = $exception->getMessage();
            }
        }

        // Report every problem in one pass — an operator on a phone call should not have to
        // round-trip the API once per unanswered question.
        if ($missing !== [] || $invalid !== []) {
            throw new ValidationException(sprintf(
                'Roadside assistance intake for "%s" is incomplete. %s',
                $this->service->label(),
                trim(implode(' ', [
                    $missing === [] ? '' : 'Missing: ' . implode(', ', $missing) . '.',
                    $invalid === [] ? '' : implode(' ', $invalid),
                ])),
            ));
        }

        return $normalized;
    }

    private function isAnswered(string $key): bool
    {
        if (! array_key_exists($key, $this->answers)) {
            return false;
        }

        $value = $this->answers[$key];

        if ($value === null || $value === []) {
            return false;
        }

        return ! is_string($value) || trim($value) !== '';
    }

    /**
     * A dependent field ("Armor level") is only demanded once its parent boolean ("Is it armored?")
     * comes back true, so a non-armored sedan never has to answer it.
     */
    private function isRequired(RoadsideIntakeField $field): bool
    {
        if (! $field->required || $field->requiredWhen === null) {
            return $field->required;
        }

        if (! $this->isAnswered($field->requiredWhen)) {
            return false;
        }

        try {
            return $this->toBoolean($this->answers[$field->requiredWhen]);
        } catch (ValidationException) {
            return false;
        }
    }

    private function cast(RoadsideIntakeField $field, mixed $value): mixed
    {
        return match ($field->type) {
            RoadsideIntakeFieldTypeEnum::BOOLEAN => $this->toBoolean($value, $field),
            RoadsideIntakeFieldTypeEnum::NUMBER => $this->toNumber($value, $field),
            RoadsideIntakeFieldTypeEnum::CHOICE => $this->toChoice($value, $field),
            RoadsideIntakeFieldTypeEnum::DATETIME => $this->toDateTime($value, $field),
            RoadsideIntakeFieldTypeEnum::TEXT => trim((string) $value),
        };
    }

    /**
     * Operators type the answer in either language, so both are accepted here — this is input
     * parsing, not vocabulary.
     */
    private function toBoolean(mixed $value, ?RoadsideIntakeField $field = null): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $needle = strtolower(trim((string) $value));

        if (in_array($needle, self::TRUTHY, true)) {
            return true;
        }

        if (in_array($needle, self::FALSY, true)) {
            return false;
        }

        throw new ValidationException(sprintf(
            '"%s" must be yes or no.',
            $field?->label ?? $needle,
        ));
    }

    private function toNumber(mixed $value, RoadsideIntakeField $field): int|float
    {
        if (! is_numeric($value)) {
            throw new ValidationException(sprintf('"%s" must be a number.', $field->label));
        }

        return $value + 0;
    }

    private function toChoice(mixed $value, RoadsideIntakeField $field): string
    {
        $needle = strtolower(trim((string) $value));

        foreach ($field->options as $option) {
            if (strtolower($option) === $needle) {
                return $option;
            }
        }

        throw new ValidationException(sprintf(
            '"%s" must be one of: %s.',
            $field->label,
            implode(', ', $field->options),
        ));
    }

    private function toDateTime(mixed $value, RoadsideIntakeField $field): string
    {
        try {
            return Carbon::parse((string) $value)->toISOString();
        } catch (Throwable) {
            throw new ValidationException(sprintf('"%s" must be a valid date and time.', $field->label));
        }
    }
}
