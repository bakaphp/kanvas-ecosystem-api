<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Scribe\Quotes\Models\Quote;

/**
 * Resolves a quote by id for a tool, scoped to the agent's own tenant. Requires HasKanvasContext
 * ($app, $company). Returns the Quote, or an LLM-facing error array the caller merges into its own
 * response shape. Mirrors ResolvesPushedInvoiceForTool on the AR-document side.
 */
trait ResolvesScribeQuoteForTool
{
    use HasKanvasContext;

    /**
     * @return Quote|array{reason: string, message: string}
     */
    protected function resolveQuote(int $quoteId): Quote|array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('quote');
        }

        /** @var Quote|null $quote */
        $quote = Quote::query()
            ->where('id', $quoteId)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->first();

        if ($quote === null) {
            return [
                'reason' => 'quote_not_found',
                'message' => "No quote with id {$quoteId} for this app/company.",
            ];
        }

        return $quote;
    }
}
