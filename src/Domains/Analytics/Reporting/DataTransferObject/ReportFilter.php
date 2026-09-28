<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Reporting\DataTransferObject;

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

    /**
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            column: (string) ($input['column'] ?? ''),
            operator: strtoupper(trim((string) ($input['operator'] ?? '='))),
            value: $input['value'] ?? null,
        );
    }

    public function needsValue(): bool
    {
        return ! in_array($this->operator, ['IS NULL', 'IS NOT NULL'], true);
    }
}
