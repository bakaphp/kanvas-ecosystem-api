<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Reporting\DataTransferObject;

use Baka\Support\Str;
use Kanvas\Exceptions\ValidationException;

/**
 * One condition against a flat table.
 *
 * The operator set is deliberately the one the Gestor actually uses and nothing more. Letting
 * this grow into a general query language is how the legacy search engine became the thing
 * nobody could reason about.
 */
final class ReportFilter
{
    public const array OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'LIKE', 'IN', 'NOT IN', 'BETWEEN', 'IS NULL', 'IS NOT NULL', 'MEMBER OF'];

    public function __construct(
        public readonly string $column,
        public readonly string $operator,
        public readonly mixed $value = null,
    ) {
        if (! in_array($this->operator, self::OPERATORS, true)) {
            throw new ValidationException('Unsupported report filter operator: ' . $this->operator);
        }
    }

    /** Operators whose value is a list, however the caller spelled it. */
    private const array LIST_OPERATORS = ['IN', 'NOT IN', 'BETWEEN'];

    /**
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input): self
    {
        $operator = strtoupper(trim((string) ($input['operator'] ?? '=')));

        return new self(
            column: (string) ($input['column'] ?? ''),
            operator: $operator,
            value: self::normalizeValue($operator, $input['value'] ?? null),
        );
    }

    /**
     * A list operator handed a plain string is read as comma-separated.
     *
     * Tool schemas have to declare `value` as a scalar: Gemini rejects a union type outright, and
     * an array-of-anything is the shape that already broke a whole turn's tool list once. So an
     * LLM calling `run_report` sends `IN` values as `"CONFIRMADO, PROGRAMA"`, and without this
     * they would reach the query builder as one string and match nothing — silently, since that
     * is a legitimately empty result rather than an error.
     *
     * Only list operators split, so a value that legitimately contains a comma ("Banco, S.A."
     * under `=`) is never cut in half.
     */
    private static function normalizeValue(string $operator, mixed $value): mixed
    {
        if (! is_string($value) || ! in_array($operator, self::LIST_OPERATORS, true)) {
            return $value;
        }

        return Str::commaList($value);
    }

    public function needsValue(): bool
    {
        return ! in_array($this->operator, ['IS NULL', 'IS NOT NULL'], true);
    }
}
