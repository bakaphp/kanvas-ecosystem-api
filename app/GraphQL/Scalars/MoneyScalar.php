<?php

declare(strict_types=1);

namespace App\GraphQL\Scalars;

class MoneyScalar extends DecimalScalar
{
    public string $name = 'Money';

    /**
     * Serialize the value to ensure it is a valid monetary value.
     */
    public function serialize($value): string
    {
        // Reuse Decimal logic
        $formattedDecimal = parent::serialize($value);

        // Additional logic for Money if needed (e.g., ensuring a positive value)
        if ((float) $formattedDecimal < 0) {
            throw new \InvalidArgumentException("Money cannot be negative: {$formattedDecimal}");
        }

        return $formattedDecimal;
    }

    /**
     * Parse the value from the client input.
     */
    public function parseValue($value): string
    {
        // Reuse Decimal logic
        return parent::parseValue($value);
    }

    /**
     * Parse the value from the GraphQL query AST.
     */
    public function parseLiteral($valueNode, ?array $variables = null): string
    {
        // Reuse Decimal logic
        return parent::parseLiteral($valueNode, $variables);
    }
}
