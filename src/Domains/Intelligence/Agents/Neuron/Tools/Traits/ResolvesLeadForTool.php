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
        } catch (ModelNotFoundException) {
            return [
                'status' => 'error',
                'message' => "Lead {$leadId} does not exist. You invented this lead_id — never do that. "
                    . 'DO NOT ask the prospect for their lead_id (they do not have one and do not know what that means). '
                    . 'Instead, immediately call create_lead yourself with whatever prospect details you have gathered '
                    . 'in the conversation (name, company, email or phone, what they said). create_lead will return a '
                    . 'real lead_id — then retry this tool with that lead_id, in the SAME turn.',
            ];
        }
    }
}
