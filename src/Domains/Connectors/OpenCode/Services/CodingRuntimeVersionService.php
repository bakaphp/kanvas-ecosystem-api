<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Services;

/**
 * The one opencode version both runtimes run, read off the Dockerfile that builds the container image.
 *
 * The container tag (`kanvas/opencode:{v}`) and the host binary (`{root}/bin/opencode-{v}`) are two
 * spellings of this number, never two numbers: a mixed Linux + Mac app has one source of truth, and
 * the version moves when the Dockerfile moves, in a commit somebody reviewed.
 *
 * Read from `FROM` rather than held as a constant beside it, because a constant beside a Dockerfile is
 * just another copy that can disagree with what `docker build` actually produces.
 */
final class CodingRuntimeVersionService
{
    public const string RELEASE_BASE = 'https://github.com/anomalyco/opencode/releases/download/v';
    public const string ARCHIVE = 'opencode-darwin-arm64.zip';
    public const string CHECKSUMS = 'SHA256SUMS';

    /**
     * sha256 of ARCHIVE per version, used only when the release publishes no CHECKSUMS file. The
     * commit that bumps the Dockerfile adds the next entry.
     *
     * @var array<string, string>
     */
    public const array HOST_ARCHIVE_SHA256 = [
        '2.2.5' => '25ba9908979fa7d764792c82589b4b5768ab1511154e0317d98aa77d216ce767',
    ];

    private const string DOCKERFILE = 'docker/opencode/Dockerfile';

    /** Used only when the Dockerfile cannot be read or parsed, so a caller still gets a usable version. */
    private const string FALLBACK_VERSION = '2.2.5';

    public static function dockerfilePath(): string
    {
        return base_path(self::DOCKERFILE);
    }

    public static function upstreamVersion(): string
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

    /**
     * `kanvas/opencode:2.2.5` → `2.2.5`; a bare version is returned as it is.
     */
    public static function versionOf(string $imageTag): string
    {
        $colon = strrpos($imageTag, ':');

        return $colon === false ? $imageTag : substr($imageTag, $colon + 1);
    }

    public static function hostBinary(string $root, string $version): string
    {
        return rtrim($root, '/') . '/bin/opencode-' . $version;
    }

    public static function archiveUrl(string $version): string
    {
        return self::RELEASE_BASE . $version . '/' . self::ARCHIVE;
    }

    public static function checksumsUrl(string $version): string
    {
        return self::RELEASE_BASE . $version . '/' . self::CHECKSUMS;
    }
}
