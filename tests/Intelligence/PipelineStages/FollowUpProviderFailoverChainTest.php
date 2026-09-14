<?php

declare(strict_types=1);

namespace Tests\Intelligence\PipelineStages;

use Kanvas\Intelligence\PipelinesStages\Actions\CreateMessageFollowUpAction;
use Laravel\Ai\Enums\Lab;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The follow-up generator pins Gemini, which regularly answers 503 "this model is currently
 * experiencing high demand" (Sentry KANVAS-ECOSYSTEM-5FV). laravel/ai only fails over when the
 * `provider` argument carries more than one leg, so these lock the chain's shape.
 */
final class FollowUpProviderFailoverChainTest extends TestCase
{
    public function testGeminiIsAlwaysThePrimaryLeg(): void
    {
        $chain = $this->chain();

        $this->assertSame(Lab::Gemini->value, array_key_first($chain));
        $this->assertSame('gemini-2.5-pro', $chain[Lab::Gemini->value]);
    }

    public function testAppendsAFallbackLegOnlyWhenItsKeyIsConfigured(): void
    {
        config([
            'ai.providers.openai.key' => null,
            'ai.providers.anthropic.key' => null,
        ]);

        $this->assertCount(
            1,
            $this->chain(),
            'An unconfigured fallback would swap a recoverable overload for a credential error.'
        );

        config(['ai.providers.anthropic.key' => 'test-key']);

        $chain = $this->chain();

        $this->assertCount(2, $chain);
        $this->assertSame('claude-sonnet-4', $chain[Lab::Anthropic->value]);
    }

    public function testPrefersOpenAiOverAnthropicWhenBothAreConfigured(): void
    {
        config([
            'ai.providers.openai.key' => 'test-key',
            'ai.providers.anthropic.key' => 'test-key',
        ]);

        $chain = $this->chain();

        // One fallback, not two: a third leg triples the worst-case latency of a doomed turn.
        $this->assertCount(2, $chain);
        $this->assertArrayHasKey(Lab::OpenAI->value, $chain);
    }

    /**
     * Each leg must name its own model — laravel/ai ignores the `model:` argument once `provider`
     * is an array, so a null model would silently fall through to the provider default.
     *
     * @return array<string, string>
     */
    private function chain(): array
    {
        $method = new ReflectionMethod(CreateMessageFollowUpAction::class, 'providerFailoverChain');

        /** @var array<string, string> $chain */
        $chain = $method->invoke(null);

        foreach ($chain as $model) {
            $this->assertNotEmpty($model);
        }

        return $chain;
    }
}
