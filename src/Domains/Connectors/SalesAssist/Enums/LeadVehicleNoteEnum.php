<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Enums;

/**
 * Lead custom fields that announce themselves in the lead's default channel when they change.
 */
enum LeadVehicleNoteEnum: string
{
    case TRADE_IN = 'trade-in';
    case VEHICLE_OF_INTEREST = 'vehicle-of-interest';

    public static function fromCustomField(string $name): ?self
    {
        foreach (self::cases() as $kind) {
            if ($kind->customField() === $name) {
                return $kind;
            }
        }

        return null;
    }

    public function label(): string
    {
        return match ($this) {
            self::TRADE_IN => 'Trade In',
            self::VEHICLE_OF_INTEREST => 'Vehicle of Interest',
        };
    }

    public function title(): string
    {
        return $this->label() . ' Added';
    }

    public function customField(): string
    {
        return match ($this) {
            self::TRADE_IN => LeadCustomFieldEnum::TRADE_IN->value,
            self::VEHICLE_OF_INTEREST => LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value,
        };
    }

    /**
     * Fingerprint of the last value announced, so a CRM re-sync of the same vehicle stays quiet.
     */
    public function noteHashKey(): string
    {
        return 'sa_' . str_replace('-', '_', $this->value) . '_note_hash';
    }
}
