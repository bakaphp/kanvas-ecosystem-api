<?php

declare(strict_types=1);

namespace App\GraphQL\Scalars;

use GraphQL\Error\Error;
use GraphQL\Error\InvariantViolation;
use GraphQL\Language\AST\FloatValueNode;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\Node;
use GraphQL\Type\Definition\ScalarType;

class DecimalScalar extends ScalarType
{
    public string $name = 'Decimal';

    private const INVALID_SERIALIZATION_ERROR = 'Could not serialize value as decimal: ';
    private const INVALID_VALUE_ERROR = 'Cannot represent the following value as a decimal: ';
    private const INVALID_TYPE_ERROR = 'Query error: Can only parse numbers, got: ';

    /**
     * Serialize the value for output.
     *
     * @param mixed $value
     * @throws InvariantViolation
     */
    public function serialize($value): string
    {
        if ($value === null) {
            return '0.00';
        }

        $this->ensureIsNumeric($value, self::INVALID_SERIALIZATION_ERROR);

        return $this->formatDecimal($value);
    }

    /**
     * Parse the value from client input.
     *
     * @param mixed $value
     * @throws Error
     */
    public function parseValue($value): string
    {
        if ($value === null) {
            return '0.00';
        }

        $this->ensureIsNumeric($value, self::INVALID_VALUE_ERROR);

        return $this->formatDecimal($value);
    }

    /**
     * Parse the value from the GraphQL query AST.
     *
     * @throws Error
     */
    public function parseLiteral(Node $valueNode, ?array $variables = null): string
    {
        if ($valueNode === null) {
            return '0.00';
        }

        if (! ($valueNode instanceof FloatValueNode || $valueNode instanceof IntValueNode)) {
            throw new Error(self::INVALID_TYPE_ERROR . $valueNode->kind, [$valueNode]);
        }

        return $this->formatDecimal($valueNode->value);
    }

    /**
     * Format the number to ensure it has proper decimal precision.
     *
     * @param mixed $value
     */
    protected function formatDecimal($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * Ensure the value is numeric.
     *
     * @param mixed $value
     * @throws Error|InvariantViolation
     */
    private function ensureIsNumeric($value, string $errorMessage): void
    {
        if (! is_numeric($value)) {
            $exceptionClass = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] === 'serialize'
                ? InvariantViolation::class
                : Error::class;

            throw new $exceptionClass($errorMessage . $value);
        }
    }
}
