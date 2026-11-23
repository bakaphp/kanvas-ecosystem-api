<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Intelligence\Agents\Services\ModelPricingCalculator;
use Tests\TestCase;

final class ModelPricingInferProviderTest extends TestCase
{
    public function testBedrockIdsAreToldApartFromTheVendorsOwnIds(): void
    {
        $expected = [
            'global.anthropic.claude-haiku-5-5' => 'bedrock',
            'us.amazon.nova-lite-v1:0' => 'bedrock',
            'amazon.titan-embed-text-v2:0' => 'bedrock',
            'global.openai.gpt-6-luna' => 'bedrock',
            'claude-haiku-5-5' => 'anthropic',
            'gemini-3.8-flash' => 'google',
            'google/gemini-3-flash-preview' => 'google',
            'gpt-6-luna' => 'openai',
        ];

        foreach ($expected as $model => $provider) {
            $this->assertSame($provider, ModelPricingCalculator::inferProvider($model), $model);
        }
    }
}
