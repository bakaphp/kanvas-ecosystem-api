<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Enrichment\Services;

use Kanvas\Intelligence\Agents\Services\ModelPricingCalculator;

/**
 * Prices and totals the usage EnrichProductAction reports, so a first run on a new tenant shows
 * what the whole catalog would cost before anyone commits to it.
 */
class EnrichmentUsageService
{
    private int $inputTokens = 0;
    private int $outputTokens = 0;
    private float $cost = 0.0;
    private int $unpriced = 0;

    public function __construct(
        private readonly ModelPricingCalculator $calculator,
    ) {
    }

    /**
     * @param array{input_tokens: int, output_tokens: int, model: ?string} $usage
     *
     * @return string the per-product line
     */
    public function record(array $usage): string
    {
        $model = $usage['model'];
        $provider = $model !== null ? ModelPricingCalculator::inferProvider($model) : null;

        $this->inputTokens += $usage['input_tokens'];
        $this->outputTokens += $usage['output_tokens'];

        $cost = 'n/a';

        if ($this->calculator->hasPricingFor($provider, $model)) {
            $amount = $this->calculator->costFor(
                provider: $provider,
                model: $model,
                inputTokens: $usage['input_tokens'],
                outputTokens: $usage['output_tokens'],
            );
            $this->cost += $amount;
            $cost = $this->formatCost($amount);
        } else {
            $this->unpriced++;
        }

        return sprintf(
            '%d in / %d out tokens, %s (%s)',
            $usage['input_tokens'],
            $usage['output_tokens'],
            $cost,
            $model ?? 'unknown model',
        );
    }

    /**
     * @return list<string>
     */
    public function totals(): array
    {
        $lines = [sprintf(
            'Tokens: %d in / %d out. Estimated cost: %s.',
            $this->inputTokens,
            $this->outputTokens,
            $this->formatCost($this->cost),
        )];

        if ($this->unpriced > 0) {
            $lines[] = sprintf(
                '%d product(s) ran on a model with no model_pricing row and are not in that cost.',
                $this->unpriced,
            );
        }

        return $lines;
    }

    private function formatCost(float $amount): string
    {
        return '$' . number_format($amount, 6);
    }
}
