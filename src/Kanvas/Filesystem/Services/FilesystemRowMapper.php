<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Baka\Support\Str;
use Baka\Validations\Date;

/**
 * The value language of a FilesystemMapper template, shared by every mapper-driven import so a template
 * means the same thing whichever entity it imports. Plain arrays are nested templates: the calling
 * action recurses into them itself, because the per-key post-processing (aliases, categories, tags)
 * differs by entity.
 */
final class FilesystemRowMapper
{
    private const array EXPRESSIONS = ['$concat', '$coalesce', '$map'];

    public static function isExpression(mixed $value): bool
    {
        return is_array($value) && array_intersect(self::EXPRESSIONS, array_keys($value)) !== [];
    }

    public static function resolve(mixed $template, array $row): mixed
    {
        if (is_array($template)) {
            return match (true) {
                array_key_exists('$concat', $template) => self::concat($template, $row),
                array_key_exists('$coalesce', $template) => self::coalesce((array) $template['$coalesce'], $row),
                array_key_exists('$map', $template) => self::lookup($template, $row),
                default => $template,
            };
        }

        if (! is_string($template)) {
            return $template;
        }

        // A real column wins over the `extra.` prefix so existing CSVs with such a header keep mapping.
        return match (true) {
            str_starts_with($template, '_') => substr($template, 1),
            str_starts_with($template, 'date_') => Date::createFromFormat((string) ($row[substr($template, 5)] ?? '')),
            array_key_exists($template, $row) => $row[$template],
            str_starts_with($template, 'extra.') => data_get($row['extra'] ?? null, substr($template, 6)),
            default => null,
        };
    }

    /**
     * The resolved value as trimmed text, or null when it is empty or not a scalar.
     */
    public static function resolveText(mixed $template, array $row): ?string
    {
        return self::text(self::resolve($template, $row));
    }

    private static function concat(array $expression, array $row): string
    {
        $parts = [];
        foreach ((array) $expression['$concat'] as $part) {
            $text = self::text(self::resolve($part, $row));
            if ($text !== null) {
                $parts[] = $text;
            }
        }

        return implode((string) ($expression['$sep'] ?? ' '), $parts);
    }

    private static function coalesce(array $candidates, array $row): mixed
    {
        foreach ($candidates as $candidate) {
            $value = self::resolve($candidate, $row);

            if (is_string($value)) {
                $value = Str::trimToNull($value);
            }

            if ($value !== null && $value !== []) {
                return $value;
            }
        }

        return null;
    }

    private static function lookup(array $expression, array $row): mixed
    {
        $key = Str::lowerTrim(self::text(self::resolve($expression['$map'], $row)));

        $values = [];
        foreach ((array) ($expression['values'] ?? []) as $from => $to) {
            $values[Str::lowerTrim((string) $from)] = $to;
        }

        return array_key_exists($key, $values) ? $values[$key] : ($expression['default'] ?? null);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) || is_int($value) || is_float($value)
            ? Str::trimToNull((string) $value)
            : null;
    }
}
