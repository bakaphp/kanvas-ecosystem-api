<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Claims\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Claims\Models\EntityClaim;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;

/**
 * Release a held claim. Hard-deletes the row so the unique slot frees for the
 * next acquirer, then records claim.released in the ledger. Idempotent: a claim
 * already gone (released elsewhere or expired-and-cleared) is a no-op.
 */
class ReleaseEntityClaimAction
{
    public function __construct(
        private readonly EntityClaim $claim,
    ) {
    }

    public function execute(): void
    {
        if (! $this->claim->exists) {
            return;
        }

        $appsId = $this->claim->apps_id;
        $companiesId = $this->claim->companies_id;
        $entityNamespace = $this->claim->entity_namespace;
        $entityId = $this->claim->entity_id;
        $agentId = $this->claim->agent_id;
        $claimUuid = $this->claim->uuid;
        $correlationId = $this->claim->correlation_id;

        $this->claim->delete();

        /** @var Apps $app */
        $app = Apps::getById($appsId);
        $company = Companies::getById($companiesId);

        new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'NervousSystem',
                eventType: 'claim.released',
                status: EventStatusEnum::INFO,
                sourceEntityType: $entityNamespace,
                sourceEntityId: $entityId,
                actorType: 'Agent',
                actorId: $agentId,
                payload: ['claim_uuid' => $claimUuid],
                correlationId: $correlationId,
            ),
        )->execute();
    }
}
