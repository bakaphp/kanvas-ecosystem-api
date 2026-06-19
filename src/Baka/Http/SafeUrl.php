<?php

declare(strict_types=1);

namespace Baka\Http;

use Baka\Http\Exceptions\SsrfException;

/**
 * SSRF guard for server-side URL fetches.
 *
 * Validates a URL before the server is allowed to request it: only http(s) schemes are
 * permitted, the host is resolved, and every resolved address must be publicly routable
 * (no loopback, RFC1918, link-local — including the 169.254.169.254 cloud-metadata
 * endpoint — CGNAT or other reserved ranges).
 */
final class SafeUrl
{
    /**
     * Baseline blocked ranges PHP's FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE don't cover.
     * Hardcoded (not config-only) so the security floor holds even if config fails to load;
     * `config('ssrf.blocked_cidrs')` is merged on top for operator-specific additions.
     *
     * @var list<string>
     */
    private const RESERVED_CIDRS = [
        '100.64.0.0/10',    // CGNAT (RFC 6598)
        '192.0.0.0/24',     // IETF protocol assignments
        '192.0.2.0/24',     // TEST-NET-1
        '198.18.0.0/15',    // benchmarking
        '198.51.100.0/24',  // TEST-NET-2
        '203.0.113.0/24',   // TEST-NET-3
        'fc00::/7',         // unique-local IPv6
        'fe80::/10',        // link-local IPv6
        '::ffff:0:0/96',    // IPv4-mapped IPv6
        // IPv6 transition forms that embed an IPv4 address (Symfony CVE-2026-48736).
        '::/96',            // IPv4-compatible IPv6 (deprecated)
        '64:ff9b::/96',     // NAT64 well-known prefix (RFC 6052)
        '64:ff9b:1::/48',   // NAT64 local-use prefix (RFC 8215)
        '2002::/16',        // 6to4
        '2001::/32',        // Teredo
    ];

    /**
     * Validate a URL and return the public IPs the host resolves to.
     *
     * @return list<string>
     *
     * @throws SsrfException
     */
    public static function resolve(string $url): array
    {
        $allowedSchemes = config('ssrf.allowed_schemes', ['http', 'https']);

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new SsrfException('Malformed or schemeless URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, $allowedSchemes, true)) {
            throw new SsrfException("Disallowed URL scheme: {$scheme}");
        }

        // parse_url keeps the brackets on an IPv6 literal host.
        $host = trim($parts['host'], '[]');

        $ips = self::resolveHost($host);
        if ($ips === []) {
            throw new SsrfException("Could not resolve host: {$host}");
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new SsrfException("Host {$host} resolves to a non-public address: {$ip}");
            }
        }

        return $ips;
    }

    /**
     * Throw if the URL is unsafe to fetch; discard the resolved IPs.
     *
     * @throws SsrfException
     */
    public static function assertSafe(string $url): void
    {
        self::resolve($url);
    }

    /**
     * @return list<string>
     */
    private static function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        /** @var list<string> $ips */
        $ips = [];

        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $ips = array_merge($ips, $ipv4);
        }

        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    public static function isPublicIp(string $ip): bool
    {
        // Collapse an IPv4-mapped IPv6 address (::ffff:10.0.0.1) to its IPv4 form so the
        // private/reserved checks below catch it.
        if (str_contains($ip, '.') && str_contains($ip, ':')) {
            $lastColon = strrpos($ip, ':');
            $mapped = $lastColon === false ? $ip : substr($ip, $lastColon + 1);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $ip = $mapped;
            }
        }

        // PHP's own filter rejects RFC1918, loopback, link-local (incl. 169.254.169.254),
        // 0.0.0.0/8, 240/4, ::1 and fc00::/7.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $blocked = array_merge(self::RESERVED_CIDRS, config('ssrf.blocked_cidrs', []));
        foreach ($blocked as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    public static function ipInCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        $segments = explode('/', $cidr, 2);
        $subnet = $segments[0];
        $bits = (int) ($segments[1] ?? 0);

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            // Different address families (e.g. v4 ip vs v6 cidr) — no match.
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $remainderBits = $bits % 8;

        if ($wholeBytes > 0 && strncmp($ipBin, $subnetBin, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $mask = 0xff << (8 - $remainderBits) & 0xff;

        return (ord($ipBin[$wholeBytes]) & $mask) === (ord($subnetBin[$wholeBytes]) & $mask);
    }
}
