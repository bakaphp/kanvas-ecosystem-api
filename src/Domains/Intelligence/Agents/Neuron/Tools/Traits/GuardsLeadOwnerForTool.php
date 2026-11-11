<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;

/**
 * A lead's owner may direct its follow-up; anyone else needs to be an administrator. The actor is
 * the person talking to the agent, as `withContext()` set them.
 */
trait GuardsLeadOwnerForTool
{
    use GuardsAdminForTool;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    abstract protected function denied(string $error, array $payload = [], ?string $guidance = null): array;

    /**
     * The person the write is credited to. On the registry path the context user is the agent's own
     * user, so the requesting human wins whenever one is known.
     */
    protected function actingHuman(): ?Users
    {
        return $this->requestingUser ?? $this->contextUser();
    }

    /**
     * @return array<string, mixed>|null the refusal, null when allowed
     */
    protected function requireLeadOwnerOrAdminOrError(Lead $lead, string $action): ?array
    {
        $actor = $this->actingHuman();

        if ($actor !== null && (int) $lead->leads_owner_id === $actor->getId()) {
            return null;
        }

        if ($this->requireAdminOrError() === null) {
            return null;
        }

        return $this->denied(
            "Only the owner of lead {$lead->getId()} or an administrator can {$action}.",
            ['lead_id' => $lead->getId(), 'owner_users_id' => (int) $lead->leads_owner_id],
            guidance: 'Tell the person the lead belongs to someone else and who can change it.',
        );
    }
}
