<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Guild\Customers\Models\People;
use Throwable;

/**
 * Look up a People by id from a tool's __invoke, returning the person OR a structured error the LLM
 * can act on — a hallucinated id must not crash the turn.
 *
 * Pulls in HasKanvasContext because person_id is LLM-supplied and therefore prompt-injectable:
 * resolving by id alone matches any contact on the platform, handing the caller another company's
 * PII. Tenant context is a hard dependency — a tool wired without it resolves nothing rather than
 * crossing the boundary.
 *
 * Pattern:
 *
 *   $result = $this->resolvePersonOrError($person_id);
 *   if (is_array($result)) {
 *       return $result;     // tool returns the structured error to Neuron
 *   }
 *   $person = $result;      // typed People from here on
 */
trait ResolvesPersonForTool
{
    use HasKanvasContext;

    /**
     * @return People|array<string, mixed>
     */
    protected function resolvePersonOrError(int $personId): People|array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('contact');
        }

        try {
            /** @var People $person */
            $person = People::getByIdFromCompanyApp($personId, $this->company, $this->app);

            return $person;
        } catch (Throwable) {
            return ['error' => sprintf('No person #%d found in this company.', $personId)];
        }
    }
}
