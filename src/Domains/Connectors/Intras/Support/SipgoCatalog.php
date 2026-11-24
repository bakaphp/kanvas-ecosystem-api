<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Support;

use Kanvas\Connectors\Intras\Client;
use Kanvas\Exceptions\ValidationException;

/**
 * Name ⇄ id for SIPGO catalog tables (participants_levels, cities, business_sectors, ...). Kanvas only
 * stores the catalog's name, so a push has to find the id again — by exact trimmed, case-insensitive
 * name, and never by guessing: a name with no row or several rows (affiliates, cities and
 * business_activities carry duplicates) is refused so the approver never signs off on a wrong id.
 */
class SipgoCatalog
{
    /** @var array<string, array<int, string>> table => [id => name] */
    private array $names = [];

    public function __construct(
        private readonly Client $client,
    ) {
    }

    public function idFor(string $table, string $name): int
    {
        $wanted = self::normalize($name);
        $matches = array_keys(array_filter(
            $this->load($table),
            fn (string $candidate) => self::normalize($candidate) === $wanted,
        ));

        if ($matches === []) {
            throw new ValidationException(sprintf("SIPGO %s: no row named '%s'", $table, $name));
        }

        if (count($matches) > 1) {
            throw new ValidationException(sprintf("SIPGO %s: '%s' matches %d rows", $table, $name, count($matches)));
        }

        return (int) $matches[0];
    }

    public function nameFor(string $table, ?int $id): ?string
    {
        if ($id === null || $id <= 0) {
            return null;
        }

        return $this->load($table)[$id] ?? null;
    }

    public static function normalize(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * @return array<int, string>
     */
    private function load(string $table): array
    {
        return $this->names[$table] ??= $this->client->table($table)
            ->pluck('name', 'id')
            ->map(fn ($name) => trim((string) $name))
            ->all();
    }
}
