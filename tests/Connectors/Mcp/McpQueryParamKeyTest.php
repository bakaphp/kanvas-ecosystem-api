<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Kanvas\Connectors\Mcp\DataTransferObject\McpServerConfig;
use Kanvas\Connectors\Mcp\Transports\GuardedHttpMcpTransport;
use ReflectionMethod;

/**
 * Vendors that read their key from the query string rather than an Authorization header (Browserbase:
 * `?browserbaseApiKey=`). The admin still pastes only the key: the address stays a plain server URL and
 * the key is appended at send time, so it never lands in a stored address or a serialized payload.
 */
final class McpQueryParamKeyTest extends McpTestCase
{
    private const string SERVER = 'https://8.8.4.4/mcp';

    private const string KEY = 'bb-live-secret-key';

    public function testTheKeyIsAppendedToTheAddressAtSendTime(): void
    {
        $transport = $this->transportFor('browserbaseApiKey');

        $this->assertSame(
            self::SERVER . '?browserbaseApiKey=' . self::KEY,
            $this->resolvedUrl($transport)
        );
    }

    public function testAnAddressThatAlreadyHasAQueryStringKeepsIt(): void
    {
        $transport = $this->transportFor('browserbaseApiKey', self::SERVER . '?proxies=true');

        $this->assertSame(
            self::SERVER . '?proxies=true&browserbaseApiKey=' . self::KEY,
            $this->resolvedUrl($transport)
        );
    }

    public function testTheKeyNeverReachesTheSerializedTransport(): void
    {
        // The connector serializes its whole config when a workflow is interrupted, so a key that got
        // into the address would be written wherever that payload is persisted.
        $this->assertStringNotContainsString(self::KEY, serialize($this->transportFor('browserbaseApiKey')));
    }

    public function testAServerWithoutTheKnobIsUntouched(): void
    {
        $this->assertSame(self::SERVER, $this->resolvedUrl($this->transportFor(null)));
    }

    public function testTheKeyIsScrubbedFromErrorText(): void
    {
        $transport = $this->transportFor('browserbaseApiKey');
        $redact = new ReflectionMethod($transport, 'redact');

        $message = $redact->invoke($transport, 'cURL error 28: timeout for `' . self::SERVER . '?browserbaseApiKey=' . self::KEY . '`');

        // Guzzle puts the whole URL in its message, and that message becomes the grant's last_error.
        $this->assertStringNotContainsString(self::KEY, $message);
        $this->assertStringContainsString('***', $message);
    }

    public function testTheKnobIsReadFromTheServerRow(): void
    {
        $withKnob = $this->makeIntegration(['auth_query_param' => 'browserbaseApiKey']);

        $this->assertSame('browserbaseApiKey', McpServerConfig::fromIntegration($withKnob)->authQueryParam);
        $this->assertNull(McpServerConfig::fromIntegration($this->makeIntegration())->authQueryParam);
    }

    private function transportFor(?string $queryParam, string $url = self::SERVER): GuardedHttpMcpTransport
    {
        $integration = $this->makeIntegration(['url' => $url, 'auth_query_param' => $queryParam]);
        $agent = $this->makeAgent();
        $this->credentials($agent, $integration)->store(self::KEY);

        return new GuardedHttpMcpTransport(
            url: $url,
            agentsId: $agent->getId(),
            integrationsId: $integration->getId(),
            authQueryParam: $queryParam,
        );
    }

    private function resolvedUrl(GuardedHttpMcpTransport $transport): string
    {
        return new ReflectionMethod($transport, 'resolveUrl')->invoke($transport);
    }
}
