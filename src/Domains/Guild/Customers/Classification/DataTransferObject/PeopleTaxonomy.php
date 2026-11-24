<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Classification\DataTransferObject;

use Baka\Support\Str;

final readonly class PeopleTaxonomy
{
    /**
     * @param array<string, array{label: string, keywords?: list<string>, tag?: string}> $levels
     * @param array<string, array{label: string, keywords?: list<string>, tag?: string}> $areas
     */
    public function __construct(public array $levels, public array $areas)
    {
    }

    public static function fromSetting(mixed $value): ?self
    {
        $value = Str::jsonToArray($value);

        if (! is_array($value) || empty($value['levels']) || empty($value['areas'])) {
            return null;
        }

        return new self((array) $value['levels'], (array) $value['areas']);
    }

    public function hash(): string
    {
        return sha1((string) json_encode([$this->levels, $this->areas]));
    }

    public function hasLevel(?string $code): bool
    {
        return $code !== null && isset($this->levels[$code]);
    }

    public function hasArea(?string $code): bool
    {
        return $code !== null && isset($this->areas[$code]);
    }

    public function levelLabel(?string $code): ?string
    {
        return $this->levels[$code ?? '']['label'] ?? null;
    }

    public function areaLabel(?string $code): ?string
    {
        return $this->areas[$code ?? '']['label'] ?? null;
    }

    /**
     * @return list<string>
     */
    public function tagsFor(?string $level, ?string $area): array
    {
        return array_values(array_filter([
            $this->hasLevel($level) ? $this->tagOf($this->levels[$level]) : null,
            $this->hasArea($area) ? $this->tagOf($this->areas[$area]) : null,
        ]));
    }

    /**
     * @return list<string>
     */
    public function allTags(): array
    {
        return array_map(
            fn (array $entry): string => $this->tagOf($entry),
            [...array_values($this->levels), ...array_values($this->areas)],
        );
    }

    public function promptSection(): string
    {
        return "Seniority levels:\n" . $this->promptLines($this->levels)
            . "\n\nFunctional areas:\n" . $this->promptLines($this->areas);
    }

    /**
     * @param array{label: string, keywords?: list<string>, tag?: string} $entry
     */
    private function tagOf(array $entry): string
    {
        return $entry['tag'] ?? $entry['label'];
    }

    /**
     * @param array<string, array{label: string, keywords?: list<string>, tag?: string}> $entries
     */
    private function promptLines(array $entries): string
    {
        $lines = [];

        foreach ($entries as $code => $entry) {
            $lines[] = sprintf(
                '- %s (%s): %s',
                $code,
                $entry['label'],
                implode(', ', $entry['keywords'] ?? []),
            );
        }

        return implode("\n", $lines);
    }
}
