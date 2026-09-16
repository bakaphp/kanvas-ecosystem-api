<?php

declare(strict_types=1);

namespace Baka\Support;

use Illuminate\Support\Facades\File;

/**
 * Queue workers are long-lived and nothing prunes their /tmp, so every temp file must be deleted
 * on every path — including exceptions. `using()` guarantees that; `path()` never creates the file,
 * which avoids the `tempnam() . '.ext'` trap that leaves an empty file behind on each call.
 * The `kanvas-` prefix lets an out-of-band sweep target only files we own.
 */
class TempFile
{
    public const string PREFIX = 'kanvas-';

    public static function path(string $extension = '', ?string $directory = null): string
    {
        $extension = ltrim($extension, '.');

        return rtrim($directory ?? sys_get_temp_dir(), '/')
            . '/' . self::PREFIX . Str::uuid()->toString()
            . ($extension !== '' ? '.' . $extension : '');
    }

    /**
     * @template T
     *
     * @param callable(string): T $callback
     *
     * @return T
     */
    public static function using(callable $callback, string $extension = ''): mixed
    {
        $path = self::path($extension);

        try {
            return $callback($path);
        } finally {
            self::delete($path);
        }
    }

    public static function delete(?string ...$paths): void
    {
        File::delete(array_filter($paths));
    }
}
