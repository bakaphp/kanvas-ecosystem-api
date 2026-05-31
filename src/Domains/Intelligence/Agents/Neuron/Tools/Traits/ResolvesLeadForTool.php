<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * Look up a Lead by id from a tool's __invoke and return either the Lead OR a
 * structured error array the LLM can act on. Prevents the LLM's hallucinated
 * lead_ids from crashing the chat with an unhandled ModelNotFoundException.
 *
 * Pattern:
 *
 *   $result = $this->resolveLeadOrError($lead_id);
 *   if (is_array($result)) {
 *       return $result;     // tool returns the structured error to Neuron
 *   }
 *   $lead = $result;        // typed Lead from here on
 */
trait ResolvesLeadForTool
{
    /**
     * @return Lead|array{status: string, message: string}
     */
    protected function resolveLeadOrError(int $leadId): Lead|array
    {
        try {
            return Lead::getById($leadId);
        } catch (ModelNotFoundException $e) {
            return [
                'status' => 'error',
                'message' => "Lead {$leadId} not found. Do not pass a lead_id you have not received from a "
                    . 'previous tool call or that was given to you in your context. If no lead is in scope, '
                    . 'gather the prospect details and call create_lead first, then use the returned lead_id.',
            ];
        }
    }
}
