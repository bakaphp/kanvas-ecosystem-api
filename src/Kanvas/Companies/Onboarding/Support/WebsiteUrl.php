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

        // A one-word host is never a public site; it is a leftover of a mistyped scheme ("https" out of
        // "https://https//x.com") or a bare name, and either would be stored and crawled otherwise.
        if (! str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new ValidationException('Website must be a domain name, like example.com');
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

    /**
     * People type "https//x.com", "https:/x.com", "https:x.com", and paste "https://https//x.com" when
     * a form echoed the first mistake back. Every http(s) fragment at the front is peeled off, the last
     * one wins, and one well-formed scheme goes back; any other scheme is left for normalize() to refuse.
     */
    public static function withScheme(string $website): string
    {
        $website = trim($website);
        $scheme = 'https';

        while (preg_match('#^(https?)(?::/*|/+)#i', $website, $match) === 1) {
            $scheme = strtolower($match[1]);
            $website = substr($website, strlen($match[0]));
        }

        return preg_match('#^[a-z][a-z0-9+.-]*://#i', $website) ? $website : $scheme . '://' . $website;
    }

    public static function host(string $website): string
    {
        return strtolower((string) parse_url($website, PHP_URL_HOST));
    }
}
