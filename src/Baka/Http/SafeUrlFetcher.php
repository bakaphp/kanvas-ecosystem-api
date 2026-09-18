<?php

declare(strict_types=1);

namespace Baka\Http;

use Baka\Http\Exceptions\SsrfException;
use Closure;
use GuzzleHttp\Client;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * Fetches a remote URL's body with SSRF protection, a hard byte cap and a timeout.
 *
 * The validated IP is pinned for the connection (curl's CURLOPT_RESOLVE) so a DNS rebind
 * between the safety check and the actual connect cannot redirect the request to an
 * internal address. Redirects are re-validated hop by hop and restricted to http(s).
 */
final class SafeUrlFetcher
{
    private const string FAKE_BINDING = 'baka.safe-url-fetcher.fake';

    /**
     * Tests only: answer every fetch with $responder instead of the network. The fetcher builds its own
     * Guzzle client, so Http::fake() never reaches it. Held in the container, which every test rebuilds,
     * and ignored outside a unit-test run.
     *
     * @param Closure(string): string $responder
     */
    public static function fake(Closure $responder): void
    {
        app()->instance(self::FAKE_BINDING, $responder);
    }

    /**
     * @throws SsrfException on an unsafe URL or an oversized response
     * @throws \GuzzleHttp\Exception\GuzzleException on a transport error
     */
    public static function fetch(string $url): string
    {
        if (app()->runningUnitTests() && app()->bound(self::FAKE_BINDING)) {
            return app(self::FAKE_BINDING)($url);
        }

        $maxBytes = (int) config('ssrf.max_bytes', 50 * 1024 * 1024);

        $ips = SafeUrl::resolve($url);

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? 'http');
        $host = trim($parts['host'] ?? '', '[]');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        $resolveMap = array_map(
            static fn (string $ip): string => "{$host}:{$port}:{$ip}",
            $ips
        );

        $client = new Client([
            'connect_timeout' => (float) config('ssrf.connect_timeout', 5),
            'timeout' => (float) config('ssrf.timeout', 15),
            'stream' => true,
            'headers' => [
                'User-Agent' => (string) config('ssrf.user_agent', 'KanvasBot/1.0 (+https://kanvas.dev)'),
            ],
            'allow_redirects' => [
                'max' => (int) config('ssrf.max_redirects', 3),
                'strict' => true,
                'referer' => false,
                'protocols' => ['http', 'https'],
                'on_redirect' => static function (RequestInterface $request, ResponseInterface $response, UriInterface $uri): void {
                    SafeUrl::assertSafe((string) $uri);
                },
            ],
            'curl' => [
                CURLOPT_RESOLVE => $resolveMap,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ],
        ]);

        $response = $client->get($url);

        $declaredLength = $response->getHeaderLine('Content-Length');
        if ($declaredLength !== '' && (int) $declaredLength > $maxBytes) {
            throw new SsrfException("Remote response exceeds the maximum allowed size of {$maxBytes} bytes.");
        }

        $body = $response->getBody();
        $buffer = '';
        while (! $body->eof()) {
            $buffer .= $body->read(8192);
            if (strlen($buffer) > $maxBytes) {
                throw new SsrfException("Remote response exceeds the maximum allowed size of {$maxBytes} bytes.");
            }
        }

        return $buffer;
    }
}
