<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence\Concerns;

use NeuronAI\Providers\AIProviderInterface;
use Override;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\Stubs\Intelligence\LeadSummaryStubProviders;
use Tests\Stubs\Intelligence\ScriptedStructuredNeuronProvider;

/**
 * The rolling lead summary switched on, with both providers reachable from the test through
 * LeadSummaryStubProviders: the reply provider records the instructions of the first request, the
 * summary provider answers the catch-up. A window small enough that a handful of messages overflow it.
 */
trait UsesLeadSummaryStubProviders
{
    #[Override]
    protected function provider(): AIProviderInterface
    {
        return LeadSummaryStubProviders::$chats ??= new FakeNeuronProvider('ok');
    }

    #[Override]
    protected function contextWindow(): int
    {
        return LeadSummaryStubProviders::$window;
    }

    #[Override]
    protected function leadSummaryActive(): bool
    {
        return true;
    }

    #[Override]
    protected function leadSummaryProvider(): AIProviderInterface
    {
        return LeadSummaryStubProviders::$summaries ?? new ScriptedStructuredNeuronProvider([]);
    }
}
