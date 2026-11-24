<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Support;

use Illuminate\Support\Carbon;
use Kanvas\Companies\Support\CompanyScope;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Throwable;

/**
 * One event per turn and reach (the scope's ids), stamped with the tool that first crossed; a failed
 * write never fails the read.
 */
final class CrossCompanyReadLedger
{
    public const string EVENT_TYPE = 'agent.cross_company_read';

    public static function record(CompanyScope $scope, string $tool): void
    {
        if (! $scope->shouldAudit() || ! $scope->markAudited(implode(',', $scope->companyIds))) {
            return;
        }

        try {
            new AppendEventAction(
                new EventData(
                    app: $scope->app,
                    company: $scope->ownCompany,
                    sourceDomain: 'Intelligence',
                    eventType: self::EVENT_TYPE,
                    status: EventStatusEnum::INFO,
                    sourceEntityType: $scope->agentId !== null ? Agent::class : null,
                    sourceEntityId: $scope->agentId,
                    actorType: $scope->requestingUser !== null ? 'User' : 'System',
                    actorId: $scope->requestingUser?->getId(),
                    payload: ['first_tool' => $tool, 'companies' => $scope->companyIds, 'narrowed' => $scope->narrowed],
                    occurredAt: Carbon::now(),
                ),
            )->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
