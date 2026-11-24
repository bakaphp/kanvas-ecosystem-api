<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Services;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

/**
 * The container image tag, derived from the version `CodingRuntimeVersionService` reads off the
 * Dockerfile. Nobody types a version into a command: a hardcoded default outlives the Dockerfile it
 * was copied from.
 *
 * **Pinned, never floating.** A `:2` or `:latest` tag lets two boxes carry different images under one
 * name, because `apk` resolves at build time.
 */
class CodingImageService
{
    /** Local repository name. The upstream image is rebuilt with a toolchain, so it is not upstream's. */
    private const string REPOSITORY = 'kanvas/opencode';

    /**
     * The tag a command was given, or the pinned one when it was given none.
     */
    public static function resolveTag(?string $requested): string
    {
        return Str::trimToNull((string) $requested) ?? self::pinnedTag();
    }

    /**
     * `kanvas/opencode:<version>`, where the version is the upstream tag the Dockerfile builds on.
     */
    public static function pinnedTag(): string
    {
        return self::REPOSITORY . ':' . CodingRuntimeVersionService::upstreamVersion();
    }

    /**
     * The image the app's containers run, which is only the pinned tag once something has written it.
     */
    public static function configuredFor(AppInterface $app): string
    {
        $image = Str::trimToNull((string) $app->get(ConfigurationEnum::IMAGE->value));

        if ($image === null) {
            throw new ValidationException('No opencode image configured (' . ConfigurationEnum::IMAGE->value . ')');
        }

        return $image;
    }

    /**
     * Points the app at an image; true when that changed anything.
     */
    public static function configure(Apps $app, string $image): bool
    {
        if ((string) $app->get(ConfigurationEnum::IMAGE->value) === $image) {
            return false;
        }

        $app->set(ConfigurationEnum::IMAGE->value, $image);

        return true;
    }
}
