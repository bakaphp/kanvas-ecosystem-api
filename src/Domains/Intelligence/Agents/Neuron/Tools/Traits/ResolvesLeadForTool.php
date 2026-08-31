<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * Look up a Lead by id from a tool's __invoke, returning the Lead OR a structured error the LLM can
 * act on — a hallucinated id must not crash the chat.
 *
 * Pulls in HasKanvasContext because lead_id is LLM-supplied and therefore prompt-injectable:
 * resolving by id alone matches any lead on the platform, handing a prospect another company's PII
 * or aiming an email/SMS at their lead. Tenant context is a hard dependency — a tool wired without
 * it resolves nothing rather than crossing the boundary.
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
    use HasKanvasContext;

    /**
     * @return Lead|array{status: string, message: string}
     */
    protected function resolveLeadOrError(int $leadId): Lead|array
    {
        if (! isset($this->company)) {
            report(new ValidationException(
                static::class . ' tried to resolve a lead with no tenant context. '
                . 'Register the tool through mergeRegisteredTools()/addToolContext(), or call '
                . 'withContext($app, $company, $user) on it.'
            ));

            return $this->leadNotResolvedError($leadId);
        }

        try {
            return isset($this->app)
                ? Lead::getByIdFromCompanyApp($leadId, $this->company, $this->app)
                : Lead::getByIdFromCompany($leadId, $this->company);
        } catch (ModelNotFoundException) {
            return $this->leadNotResolvedError($leadId);
        }
    }

    /**
     * @return array{status: string, message: string}
     */
    private function leadNotResolvedError(int $leadId): array
    {
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
