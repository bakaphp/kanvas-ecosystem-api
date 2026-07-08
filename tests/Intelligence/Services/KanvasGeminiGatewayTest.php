<?php

declare(strict_types=1);

namespace Tests\Intelligence\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Kanvas\Intelligence\Services\KanvasGeminiGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\GeminiProvider;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\WebSearch;
use Tests\TestCase;

class KanvasGeminiGatewayTest extends TestCase
{
    public function testBuildTextRequestBodyReturnsFlatBodyNotTuple(): void
    {
        [$gateway, $provider] = $this->makeGatewayAndProvider();

        $body = $gateway->exposeBuildTextRequestBody($provider, null, [], [], null, null);

        // laravel/ai 0.9 returns a single flat body keyed by 'contents', not the
        // 0.8-era [$body, $contents] tuple. Guards the tuple-destructuring regression.
        $this->assertArrayHasKey('contents', $body);
        $this->assertArrayNotHasKey(0, $body);
    }

    public function testInjectsServerSideToolFlagWhenProviderToolPresent(): void
    {
        [$gateway, $provider] = $this->makeGatewayAndProvider();

        $body = $gateway->exposeBuildTextRequestBody($provider, null, [], [new WebSearch()], null, null);

        $this->assertTrue($body['tool_config']['include_server_side_tool_invocations'] ?? false);
    }

    public function testDoesNotInjectFlagWithoutProviderTool(): void
    {
        [$gateway, $provider] = $this->makeGatewayAndProvider();

        $body = $gateway->exposeBuildTextRequestBody($provider, null, [], [], null, null);

        $this->assertArrayNotHasKey('tool_config', $body);
    }

    /**
     * @return array{0: KanvasGeminiGateway, 1: GeminiProvider}
     */
    private function makeGatewayAndProvider(): array
    {
        $dispatcher = app(Dispatcher::class);

        $gateway = new class ($dispatcher) extends KanvasGeminiGateway {
            public function exposeBuildTextRequestBody(
                Provider $provider,
                ?string $instructions,
                array $messages,
                array $tools,
                ?array $schema,
                ?TextGenerationOptions $options,
            ): array {
                return $this->buildTextRequestBody(
                    $provider,
                    $instructions,
                    $messages,
                    $tools,
                    $schema,
                    $options,
                );
            }
        };

        $provider = new GeminiProvider($gateway, ['name' => 'gemini', 'driver' => 'gemini'], $dispatcher);

        return [$gateway, $provider];
    }
}
