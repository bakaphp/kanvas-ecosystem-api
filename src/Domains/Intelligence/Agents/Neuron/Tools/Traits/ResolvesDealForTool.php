<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Deals\Models\Deal;

/**
 * Look up a Deal by id from a tool's __invoke and return either the Deal OR a
 * structured error array the LLM can act on. Prevents a hallucinated deal_id from
 * crashing the chat with an unhandled ModelNotFoundException.
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
        try {
            return Deal::getById($dealId);
        } catch (ModelNotFoundException) {
            return [
                'status' => 'error',
                'message' => "Deal {$dealId} does not exist. You invented this deal_id — never do that. "
                    . 'Use search_deals to find the right deal, or convert_lead_to_deal / create_deal to '
                    . 'create one, then retry this tool with the real deal_id it returns.',
            ];
        }
    }
}
