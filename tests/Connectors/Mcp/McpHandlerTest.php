<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Kanvas\Connectors\Mcp\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mcp\Exceptions\McpAuthException;
use Kanvas\Connectors\Mcp\Exceptions\McpFetchException;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Capability\Models\McpToolSnapshot;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Models\Integrations;
use Tests\Stubs\Connectors\Mcp\FakeMcpServer;
use Throwable;

final class McpHandlerTest extends McpTestCase
{
    public function testProvingTheCredentialStoresItAndWarmsTheSnapshot(): void
    {
        $integration = $this->makeIntegration();

        $this->assertTrue($this->handlerFor($integration, ['token' => 'tok_live'])->setup());

        $this->assertSame(
            'tok_live',
            $this->mcpCompany->get(ConfigurationEnum::TOKEN_PREFIX->forIntegration($integration->getId()))
        );

        $snapshot = McpToolSnapshot::query()->where('integrations_id', $integration->getId())->first();
        $this->assertNotNull($snapshot, 'setup() already did tools/list, so the first turn must be warm.');
        $this->assertSame(2, $snapshot->tool_count);
    }

    public function testAMissingTokenIsRefusedBeforeAnyNetworkCall(): void
    {
        $this->expectException(ValidationException::class);

        $this->handlerFor($this->makeIntegration(), ['token' => '  '])->setup();
    }

    public function testARejectedTokenIsNotLeftBehindOnTheCompany(): void
    {
        $integration = $this->makeIntegration();

        try {
            $this->handlerFor($integration, ['token' => 'bad'], new McpAuthException('401'))->setup();
            $this->fail('A rejected credential must not report a successful setup.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('rejected', $e->getMessage());
        }

        $stored = $this->mcpCompany->get(ConfigurationEnum::TOKEN_PREFIX->forIntegration($integration->getId()));
        $this->assertTrue($stored === null || $stored === '', 'A failed setup must leave nothing on file.');
    }

    public function testAnUnreachableServerFailsLoudlyRatherThanHalfConnecting(): void
    {
        $this->expectException(ValidationException::class);

        $this->handlerFor(
            $this->makeIntegration(),
            ['token' => 'tok'],
            new McpFetchException('could not resolve host')
        )->setup();
    }

    public function testTwoServersSharingAPrefixDoNotShareACredential(): void
    {
        // metadata.prefix is cosmetic and nothing enforces its uniqueness, which is exactly why the
        // credential key is derived from the integrations row id instead.
        $first = $this->makeIntegration(['prefix' => 'shared']);
        $second = $this->makeIntegration(['prefix' => 'shared']);

        $this->handlerFor($first, ['token' => 'token_one'])->setup();
        $this->handlerFor($second, ['token' => 'token_two'])->setup();

        $this->assertSame(
            'token_one',
            $this->mcpCompany->get(ConfigurationEnum::TOKEN_PREFIX->forIntegration($first->getId()))
        );
        $this->assertSame(
            'token_two',
            $this->mcpCompany->get(ConfigurationEnum::TOKEN_PREFIX->forIntegration($second->getId()))
        );
    }

    public function testARowThatIsNotAnMcpServerIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->handlerFor($this->makeIntegration(['kind' => 'rest']), ['token' => 'tok'])->setup();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function handlerFor(Integrations $integration, array $data, ?Throwable $failWith = null): McpHandler
    {
        return new class (
            $this->mcpApp,
            $this->mcpCompany,
            Regions::query()->firstOrFail(),
            $data,
            $integration,
            $failWith,
        ) extends McpHandler {
            public function __construct(
                $app,
                $company,
                $region,
                array $data,
                $integration,
                private readonly ?Throwable $failure,
            ) {
                parent::__construct($app, $company, $region, $data, $integration);
            }

            protected function connectionFor(Integrations $integration): McpConnectionService
            {
                if ($this->failure !== null) {
                    $failure = $this->failure;

                    return new class ($this->app, $this->company, $integration, $failure) extends McpConnectionService {
                        public function __construct($app, $company, $integration, private readonly Throwable $boom)
                        {
                            parent::__construct($app, $company, $integration);
                        }

                        public function fetchDescriptors(): array
                        {
                            throw $this->boom;
                        }
                    };
                }

                return new McpConnectionService(
                    $this->app,
                    $this->company,
                    $integration,
                    FakeMcpServer::listing(FakeMcpServer::twoTools())
                );
            }
        };
    }
}
