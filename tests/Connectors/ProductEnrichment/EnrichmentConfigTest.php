<?php

declare(strict_types=1);

namespace Tests\Connectors\ProductEnrichment;

use Kanvas\Connectors\ProductEnrichment\DataTransferObject\EnrichmentConfig;
use Tests\TestCase;

class EnrichmentConfigTest extends TestCase
{
    public function testDefaultVocabularyIsUsedWhenNoAgentConfig(): void
    {
        $config = EnrichmentConfig::forAgent(null);

        $this->assertContains('male', $config->facets['audience']);
        $this->assertContains('birthday', $config->facets['occasion']);
    }

    public function testCleanDropsValuesOutsideTheVocabulary(): void
    {
        $config = EnrichmentConfig::forAgent(null);

        $this->assertSame(['male'], $config->clean('audience', ['male', 'invented-tag', 42]));
        $this->assertSame([], $config->clean('audience', ['nope']));
    }

    public function testPriceTierBucketsCorrectly(): void
    {
        $config = EnrichmentConfig::forAgent(null);

        $this->assertSame('budget', $config->priceTier(100.0));
        $this->assertSame('mid', $config->priceTier(800.0));
        $this->assertSame('premium', $config->priceTier(2000.0));
        $this->assertSame('luxury', $config->priceTier(5000.0));
        $this->assertNull($config->priceTier(null));
    }
}
