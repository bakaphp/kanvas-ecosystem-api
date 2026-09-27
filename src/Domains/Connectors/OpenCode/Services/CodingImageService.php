<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Services;

/**
 * The runtime image tag, derived from the Dockerfile that builds it.
 *
 * Nobody should be typing a version into a command. The tag was hardcoded in three places and two of
 * them still said `1.18.32` long after the Dockerfile moved to 2.0.16 — a version the connector is not
 * compatible with, offered as the default to every new install.
 *
 * Read from `FROM` rather than held as a constant beside it, because a constant beside a Dockerfile is
 * just a fourth copy: it can disagree with what `docker build` actually produces, and the disagreement
 * is invisible until a container runs the wrong opencode.
 *
 * **Pinned, never floating.** A `:2` or `:latest` tag would reintroduce exactly the drift already on
 * these machines — two boxes carrying different images under one name, because `apk` resolves at build
 * time. The version moves when the Dockerfile moves, in a commit somebody reviewed.
 */
class CodingImageService
{
    /** Local repository name. The upstream image is rebuilt with a toolchain, so it is not upstream's. */
    private const string REPOSITORY = 'kanvas/opencode';

    private const string DOCKERFILE = 'docker/opencode/Dockerfile';

    /** Used only when the Dockerfile cannot be read or parsed, so a caller still gets a usable tag. */
    private const string FALLBACK_VERSION = '2.0.16';

    public static function dockerfilePath(): string
    {
        return base_path(self::DOCKERFILE);
    }

    /**
     * `kanvas/opencode:<version>`, where the version is the upstream tag the Dockerfile builds on.
     */
    public static function pinnedTag(): string
    {
        return self::REPOSITORY . ':' . self::upstreamVersion();
    }

    private static function upstreamVersion(): string
    {
        $path = self::dockerfilePath();

        if (! is_readable($path)) {
            return self::FALLBACK_VERSION;
        }

        $dockerfile = (string) file_get_contents($path);

        return preg_match('/^FROM\s+\S*opencode:(\S+)/mi', $dockerfile, $matches) === 1
            ? $matches[1]
            : self::FALLBACK_VERSION;
    }
}
