<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Reporting\DataTransferObject;

use Kanvas\Exceptions\ValidationException;

/**
 * What to group by, what to aggregate, and how to order the result.
 *
 * The function allow-list is the point. Aliases and column names end up inside a `DB::raw`, so
 * anything that is not a declared column or one of these five names never reaches the SQL —
 * `ReportQueryService::aggregate()` validates the columns, this validates the rest.
 */
final class AggregateRequest
{
    public const array FUNCTIONS = ['COUNT', 'COUNT_DISTINCT', 'SUM', 'AVG', 'MIN', 'MAX'];

    /**
     * Aliases must be plain identifiers — they are interpolated into the select list.
     */
    private const string ALIAS_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * @param list<string>                        $groupBy
     * @param array<string, array{0: string, 1: string|null}> $aggregates alias => [sql function, column]
     */
    public function __construct(
        public readonly array $groupBy = [],
        public readonly array $aggregates = [],
        public readonly ?string $orderBy = null,
        public readonly bool $descending = true,
        public readonly int $limit = 50,
    ) {
        foreach ($this->aggregates as $alias => $spec) {
            if (preg_match(self::ALIAS_PATTERN, (string) $alias) !== 1) {
                throw new ValidationException(sprintf('Invalid aggregate alias "%s".', (string) $alias));
            }

            if (! in_array($spec[0], self::FUNCTIONS, true)) {
                throw new ValidationException(sprintf('Unsupported aggregate function "%s".', $spec[0]));
            }
        }
    }

    /**
     * Build from agent- or caller-supplied input, normalising the function name and rejecting
     * anything outside the allow-list.
     *
     * @param array<int, array{function: string, column?: string|null, alias?: string}> $aggregates
     * @param list<string>                                                              $groupBy
     */
    public static function fromInput(
        array $aggregates,
        array $groupBy = [],
        ?string $orderBy = null,
        bool $descending = true,
        int $limit = 50
    ): self {
        $compiled = [];

        foreach ($aggregates as $index => $spec) {
            $function = mb_strtoupper(trim($spec['function'] ?? ''));

            if (! in_array($function, self::FUNCTIONS, true)) {
                throw new ValidationException(sprintf(
                    'Unsupported aggregate "%s". Use one of: %s.',
                    $function,
                    implode(', ', self::FUNCTIONS)
                ));
            }

            $column = $spec['column'] ?? null;
            $column = $column === null || $column === '' ? null : $column;

            if ($function !== 'COUNT' && $column === null) {
                throw new ValidationException(sprintf('%s needs a column.', $function));
            }

            // COUNT_DISTINCT is not a SQL function name, but it is the shape callers need and
            // the one the grain forces: counting rows on `inscripcion` counts registrations,
            // not people. The service renders it as COUNT(DISTINCT col).
            $alias = (string) ($spec['alias'] ?? mb_strtolower($function) . '_' . $index);
            $compiled[$alias] = [$function, $column];
        }

        return new self(
            groupBy: $groupBy,
            aggregates: $compiled,
            orderBy: $orderBy,
            descending: $descending,
            limit: $limit,
        );
    }
}
