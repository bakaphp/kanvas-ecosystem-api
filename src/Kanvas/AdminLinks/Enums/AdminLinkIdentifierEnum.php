<?php

declare(strict_types=1);

namespace Kanvas\AdminLinks\Enums;

use Illuminate\Database\Eloquent\Model;

enum AdminLinkIdentifierEnum
{
    case ID;
    case UUID;
    case SLUG;

    /**
     * The admin route sniffs the identifier for a dash to decide whether to query
     * by uuid or by id. We always hand it the uuid — stable across environments.
     */
    case EITHER;

    /**
     * Phrased for the caller that has to act on it — the raw case name reads as
     * "it expects a either", which tells an agent nothing it can retry with.
     */
    public function describe(): string
    {
        return match ($this) {
            self::ID => 'a numeric id',
            self::UUID, self::EITHER => 'a uuid',
            self::SLUG => 'a slug',
        };
    }

    /**
     * This kind of identifier, taken from a record that has it.
     *
     * Read straight off the attribute bag — `$record->uuid` on a model without the column would fall
     * through Eloquent's __get into relation resolution.
     */
    public function of(Model $record): string|int|null
    {
        $attribute = match ($this) {
            self::ID => null,
            self::UUID, self::EITHER => 'uuid',
            self::SLUG => 'slug',
        };

        if ($attribute === null) {
            return $record->getKey();
        }

        $value = $record->getAttributes()[$attribute] ?? null;

        return $value !== null ? (string) $value : null;
    }

    public function matches(string $identifier): bool
    {
        return match ($this) {
            self::ID => ctype_digit($identifier),
            self::UUID, self::EITHER => str_contains($identifier, '-'),
            self::SLUG => $identifier !== '',
        };
    }
}
