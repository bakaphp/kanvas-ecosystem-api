<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Enums;

enum ContractEnum: string
{
    // Inclusion contracts
    case INCLUSION_MAIN = '1-EO6M4QP';
    case INCLUSION_RECEPTIVO = '1-EO7PJQQ';

    // Cross Selling contracts
    case CROSS_SELLING_MAIN = '1-EO6M4QU';
    case CROSS_SELLING_RECEPTIVO = '1-EO7PJQL';

    // Stand Alone contract
    case STAND_ALONE = '1-EO6M4QZ';

    /**
     * Get contract based on type and destination
     */
    public static function getContract(string $type, string $destination): self
    {
        // For "Territorio Nacional" (receptivo)
        if ($destination === 'Territorio Nacional') {
            return match ($type) {
                'inclusion' => self::INCLUSION_RECEPTIVO,
                'cross_selling' => self::CROSS_SELLING_RECEPTIVO,
                default => self::INCLUSION_RECEPTIVO,
            };
        }

        // For international destinations (emisivo)
        return match ($type) {
            'inclusion' => self::INCLUSION_MAIN,
            'cross_selling' => self::CROSS_SELLING_MAIN,
            'stand_alone' => self::STAND_ALONE,
            default => self::INCLUSION_MAIN,
        };
    }
}
