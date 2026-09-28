<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Baka\Http\Exceptions\SsrfException;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Mcp\Services\McpOAuthDiscoveryService;

/**
 * Hosts are IP literals on purpose: `SafeUrl` short-circuits DNS for a literal, so the SSRF guard runs
 * for real without the suite depending on name resolution.
 */
final class McpOAuthDiscoveryServiceTest extends McpTestCase
{
    private const string MCP_URL = 'https://8.8.8.8/mcp';

    private const string AUTH_SERVER = 'https://1.1.1.1';

    public function testEndpointsComeFromTheAuthorizationServerTheResourceNames(): void
    {
        Http::fake([
            'https://8.8.8.8/.well-known/oauth-protected-resource/mcp' => Http::response([
                'resource' => self::MCP_URL,
                'authorization_servers' => [self::AUTH_SERVER],
            ]),
            self::AUTH_SERVER . '/.well-known/oauth-authorization-server' => Http::response($this->metadata(self::AUTH_SERVER)),
            '*' => Http::response('', 404),
        ]);

        $endpoints = $this->discovery()->endpoints();

        $this->assertSame(self::AUTH_SERVER . '/authorize', $endpoints['authorization_endpoint']);
        $this->assertSame(self::AUTH_SERVER . '/token', $endpoints['token_endpoint']);
        $this->assertSame(self::AUTH_SERVER . '/register', $endpoints['registration_endpoint']);
        $this->assertSame(self::MCP_URL, $endpoints['resource']);
    }

    public function testWithoutResourceMetadataTheServerOriginIsTheAuthorizationServer(): void
    {
        Http::fake([
            'https://8.8.8.8/.well-known/oauth-authorization-server' => Http::response($this->metadata('https://8.8.8.8')),
            '*' => Http::response('', 404),
        ]);

        $this->assertSame('https://8.8.8.8/token', $this->discovery()->endpoints()['token_endpoint']);
    }

    public function testConfiguredOverridesFillWhatDiscoveryCannotFind(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $endpoints = $this->discovery(['oauth' => ['token_url' => 'https://9.9.9.9/oauth/token']])->endpoints();

        $this->assertSame('https://9.9.9.9/oauth/token', $endpoints['token_endpoint']);
        // Nothing published at all: the 2025-03-26 spec defaults apply, registration included.
        $this->assertSame('https://8.8.8.8/authorize', $endpoints['authorization_endpoint']);
        $this->assertSame('https://8.8.8.8/register', $endpoints['registration_endpoint']);
    }

    public function testPublishedMetadataWithoutRegistrationMeansNoRegistration(): void
    {
        $metadata = $this->metadata('https://8.8.8.8');
        unset($metadata['registration_endpoint']);

        Http::fake([
            'https://8.8.8.8/.well-known/oauth-authorization-server' => Http::response($metadata),
            '*' => Http::response('', 404),
        ]);

        // Guessing /register on a server that said nothing about DCR would POST a client to an
        // endpoint it never offered.
        $this->assertNull($this->discovery()->endpoints()['registration_endpoint']);
    }

    public function testAnAuthorizationServerOnAPrivateAddressAbortsDiscovery(): void
    {
        Http::fake([
            'https://8.8.8.8/.well-known/oauth-protected-resource/mcp' => Http::response([
                'authorization_servers' => ['https://10.0.0.1'],
            ]),
            '*' => Http::response('', 404),
        ]);

        // A hostile server, not a misconfigured one — so no quiet fallback to the origin.
        $this->expectException(SsrfException::class);

        $this->discovery()->endpoints();
    }

    public function testAPinnedAuthorizationServerIsUsedInsteadOfTheAdvertisedOne(): void
    {
        Http::fake([
            'https://8.8.8.8/.well-known/oauth-protected-resource/mcp' => Http::response([
                'resource' => self::MCP_URL,
                'authorization_servers' => [self::AUTH_SERVER],
            ]),
            self::AUTH_SERVER . '/.well-known/oauth-authorization-server' => Http::response($this->metadata(self::AUTH_SERVER)),
            'https://9.9.9.9/.well-known/oauth-authorization-server' => Http::response($this->metadata('https://9.9.9.9')),
            '*' => Http::response('', 404),
        ]);

        $endpoints = $this->discovery(['oauth' => ['authorization_server' => 'https://9.9.9.9']])->endpoints();

        $this->assertSame('https://9.9.9.9/authorize', $endpoints['authorization_endpoint']);
        $this->assertSame('https://9.9.9.9/register', $endpoints['registration_endpoint']);
    }

    public function testResourceIsSentByDefaultAndCanBeLeftOut(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->assertSame(self::MCP_URL, $this->discovery()->endpoints()['resource']);
        $this->assertNull($this->discovery(['oauth' => ['include_resource' => false]])->endpoints()['resource']);
    }

    public function testEndpointsAreDiscoveredOnceAndThenServedFromCache(): void
    {
        Http::fake([
            'https://8.8.8.8/.well-known/oauth-authorization-server' => Http::response($this->metadata('https://8.8.8.8')),
            '*' => Http::response('', 404),
        ]);

        $integration = $this->makeIntegration(['url' => self::MCP_URL]);

        new McpOAuthDiscoveryService($integration)->endpoints();
        $requestsAfterFirst = count(Http::recorded());

        new McpOAuthDiscoveryService($integration)->endpoints();

        $this->assertSame($requestsAfterFirst, count(Http::recorded()));
    }

    /**
     * @param array<string, mixed> $metadataOverrides
     */
    private function discovery(array $metadataOverrides = []): McpOAuthDiscoveryService
    {
        return new McpOAuthDiscoveryService($this->makeIntegration([
            'url' => self::MCP_URL,
            'auth_methods' => ['oauth'],
            ...$metadataOverrides,
        ]));
    }

    /**
     * @return array<string, string>
     */
    private function metadata(string $issuer): array
    {
        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/authorize',
            'token_endpoint' => $issuer . '/token',
            'registration_endpoint' => $issuer . '/register',
        ];
    }
}
