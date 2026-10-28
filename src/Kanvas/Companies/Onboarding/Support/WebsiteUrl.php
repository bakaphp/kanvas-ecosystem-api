<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Support;

use Baka\Http\SafeUrl;
use Kanvas\Exceptions\ValidationException;

/**
 * The shape check a website gets when it is typed. DNS-level SSRF validation happens at fetch
 * time (SafeUrlFetcher / SafeUrl::assertSafe); here a literal private address or a local name is
 * refused so it never gets stored in the first place.
 */
final class WebsiteUrl
{
    public static function normalize(string $website): string
    {
        $website = trim($website);
        if ($website === '') {
            throw new ValidationException('Website cannot be empty');
        }

        $website = self::withScheme($website);
        $parts = parse_url($website);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($parts === false || $host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            throw new ValidationException('Website must be an http(s) address with a host');
        }

        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new ValidationException('Website must be a public address');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false && ! SafeUrl::isPublicIp($host)) {
            throw new ValidationException('Website must be a public address');
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path;
    }

    public static function withScheme(string $website): string
    {
        $website = trim($website);

        return preg_match('#^[a-z][a-z0-9+.-]*://#i', $website) ? $website : 'https://' . $website;
    }

    public static function host(string $website): string
    {
        return strtolower((string) parse_url($website, PHP_URL_HOST));
    }
}
