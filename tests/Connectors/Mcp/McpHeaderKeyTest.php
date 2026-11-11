<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Kanvas\Connectors\Mcp\DataTransferObject\McpServerConfig;
use Kanvas\Connectors\Mcp\Enums\McpAuthEnum;
use Kanvas\Connectors\Mcp\Transports\GuardedHttpMcpTransport;
use ReflectionMethod;

/**
 * Vendors that read their key bare from their own header rather than `Authorization: Bearer` (Browser Use:
 * `x-browser-use-api-key`).
 */
final class McpHeaderKeyTest extends McpTestCase
{
    private const string SERVER = 'https://8.8.4.4/mcp';

    private const string KEY = 'bu-live-secret-key';

    public function testTheKeyIsSentBareInTheVendorHeader(): void
    {
        $this->assertSame(
            ['x-browser-use-api-key' => self::KEY],
            $this->authHeaders($this->transportFor(authHeader: 'x-browser-use-api-key'))
        );
    }

    public function testAServerWithoutTheKnobStillGetsABearerToken(): void
    {
        $this->assertSame(
            ['Authorization' => 'Bearer ' . self::KEY],
            $this->authHeaders($this->transportFor())
        );
    }

    public function testAQueryParamVendorGetsNoHeaderAtAll(): void
    {
        $this->assertSame([], $this->authHeaders($this->transportFor(authQueryParam: 'browserbaseApiKey')));
    }

    public function testTheHeaderNameSurvivesSerializationButTheKeyDoesNot(): void
    {
        $serialized = serialize($this->transportFor(authHeader: 'x-browser-use-api-key'));

        $this->assertStringNotContainsString(self::KEY, $serialized);
        $this->assertSame(
            ['x-browser-use-api-key' => self::KEY],
            $this->authHeaders(unserialize($serialized))
        );
    }

    public function testTheKnobIsReadFromTheServerRow(): void
    {
        $withKnob = $this->makeIntegration(['auth_header' => 'x-browser-use-api-key']);

        $this->assertSame('x-browser-use-api-key', McpServerConfig::fromIntegration($withKnob)->authHeader);
        $this->assertNull(McpServerConfig::fromIntegration($this->makeIntegration())->authHeader);
    }

    public function testAnOAuthTokenIsABearerTokenEvenWhereAKeyGoesInTheVendorHeader(): void
    {
        $this->assertSame(
            ['Authorization' => 'Bearer ' . self::KEY],
            $this->authHeaders($this->transportFor(authHeader: 'X-Api-Key', method: McpAuthEnum::OAUTH))
        );
    }

    public function testAnOAuthTokenNeverTravelsInTheQueryString(): void
    {
        $transport = $this->transportFor(authQueryParam: 'browserbaseApiKey', method: McpAuthEnum::OAUTH);

        $this->assertSame(['Authorization' => 'Bearer ' . self::KEY], $this->authHeaders($transport));
        $this->assertSame(self::SERVER, new ReflectionMethod($transport, 'resolveUrl')->invoke($transport));
    }

    public function testAGrantStoredBeforeTheMethodWasRecordedIsToldApartByItsExpiry(): void
    {
        $transport = $this->transportFor(authHeader: 'X-Api-Key', expiresIn: 3600);

        $this->assertSame(['Authorization' => 'Bearer ' . self::KEY], $this->authHeaders($transport));
    }

    public function testARefreshKeepsTheMethodTheGrantWasObtainedWith(): void
    {
        $integration = $this->makeIntegration(['url' => self::SERVER]);
        $credentials = $this->credentials($this->makeAgent(), $integration);

        $credentials->store(self::KEY, method: McpAuthEnum::OAUTH);
        $credentials->store('rotated-token', 'rotated-refresh', 3600);

        $this->assertTrue($credentials->isOAuthGrant());
    }

    private function transportFor(
        ?string $authHeader = null,
        ?string $authQueryParam = null,
        ?McpAuthEnum $method = null,
        ?int $expiresIn = null
    ): GuardedHttpMcpTransport {
        $integration = $this->makeIntegration([
            'url' => self::SERVER,
            'auth_header' => $authHeader,
            'auth_query_param' => $authQueryParam,
        ]);
        $agent = $this->makeAgent();
        $this->credentials($agent, $integration)->store(self::KEY, expiresIn: $expiresIn, method: $method);

        return new GuardedHttpMcpTransport(
            url: self::SERVER,
            agentsId: $agent->getId(),
            integrationsId: $integration->getId(),
            authQueryParam: $authQueryParam,
            authHeader: $authHeader,
        );
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(GuardedHttpMcpTransport $transport): array
    {
        return new ReflectionMethod($transport, 'authHeaders')->invoke($transport);
    }
}
