<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Deals\Models\Deal;

/**
 * Look up a Deal by id from a tool's __invoke, returning the Deal OR a structured error the LLM can
 * act on — a hallucinated id must not crash the chat.
 *
 * deal_id is LLM-supplied and therefore prompt-injectable: resolving by id alone matches any deal on
 * the platform — another company's opportunity read back into the chat, or destroyed by delete_deal.
 * Tenant context is a hard dependency; a tool wired without it resolves nothing.
 *
 * Unlike ResolvesLeadForTool this does NOT pull in HasKanvasContext: UpdateDealTool declares its own
 * promoted private $app/$company, and a trait re-declaring them would fatal on property composition.
 * The context is read wherever the host tool put it — via HasKanvasContext or its own constructor.
 *
 * Pattern:
 *
 *   $result = $this->resolveDealOrError($deal_id);
 *   if (is_array($result)) {
 *       return $result;     // tool returns the structured error to Neuron
 *   }
 *   $deal = $result;        // typed Deal from here on
 */
trait ResolvesDealForTool
{
    /**
     * @return Deal|array{status: string, message: string}
     */
    protected function resolveDealOrError(int $dealId): Deal|array
    {
        if (! isset($this->company)) {
            report(new ValidationException(
                static::class . ' tried to resolve a deal with no tenant context. '
                . 'Register the tool through mergeRegisteredTools()/addToolContext(), or call '
                . 'withContext($app, $company, $user) on it.'
            ));

            return $this->dealNotResolvedError($dealId);
        }

        try {
            return isset($this->app)
                ? Deal::getByIdFromCompanyApp($dealId, $this->company, $this->app)
                : Deal::getByIdFromCompany($dealId, $this->company);
        } catch (ModelNotFoundException) {
            return $this->dealNotResolvedError($dealId);
        }
    }

    /**
     * @return array{status: string, message: string}
     */
    private function dealNotResolvedError(int $dealId): array
    {
        return [
            'status' => 'error',
            'message' => "Deal {$dealId} does not exist. You invented this deal_id — never do that. "
                . 'Use search_deals to find the right deal, or convert_lead_to_deal / create_deal to '
                . 'create one, then retry this tool with the real deal_id it returns.',
        ];
    }
}
