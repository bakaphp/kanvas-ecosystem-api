<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Communication\Connections\DataTransferObject\OwnedResource;
use Kanvas\Communication\Connections\Enums\SetupStatusEnum;
use Kanvas\Communication\Connections\Jobs\RunConnectionCleanupJob;
use Kanvas\Communication\Connections\Models\ChannelDefault;
use Kanvas\Communication\Connections\Models\CleanupTask;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Services\SharedRegistry;

/**
 * Phase A of a disconnect: everything that can be decided without the vendor, in one transaction.
 * The vendor calls cannot be part of it, so they are written down as cleanup tasks and run by
 * {@see RunConnectionCleanupJob} — a worker that dies halfway leaves the work on record, and the
 * number stays claimed until it is done.
 */
final class RetireConnectionAction
{
    /**
     * @param string $reason account_removed (deselected in the integration flow), integration_removed
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $reason
    ) {
    }

    public function execute(): Connection
    {
        if (! $this->connection->isLive()) {
            return $this->connection;
        }

        DB::connection('workflow')->transaction(function () {
            $this->connection->forceFill([
                'setup_status' => SetupStatusEnum::DISCONNECTING,
                'disconnect_requested_at' => now(),
                'enabled' => false,
                'last_error' => $this->reason,
            ])->saveOrFail();

            $this->retireAccountOnIntegration();
            SetConnectionEnabledAction::skipPending([$this->connection->getId()], 'connection_disconnected');
            $this->persistCleanupTasks();
            new SharedRegistry()->detach($this->connection);
            $this->moveChannelDefaults();
        });

        DB::connection('workflow')->afterCommit(
            fn () => RunConnectionCleanupJob::dispatch($this->connection->app, $this->connection)
        );

        return $this->connection;
    }

    /**
     * The disconnect intent, kept on the integration so no sweep provisions the account again while
     * it is still listed there. Only saving the integration with the account selected brings it back.
     */
    private function retireAccountOnIntegration(): void
    {
        $integrationCompany = $this->connection->integrationCompany;

        if ($integrationCompany === null) {
            return;
        }

        $config = $integrationCompany->config ?? [];
        $config['retired_accounts'] = array_values(array_unique([
            ...($config['retired_accounts'] ?? []),
            $this->connection->account_key,
        ]));

        $integrationCompany->config = $config;
        $integrationCompany->saveOrFail();
    }

    private function persistCleanupTasks(): void
    {
        foreach ($this->connection->provisioning['owned'] ?? [] as $resource) {
            $resource = OwnedResource::fromArray($resource);

            CleanupTask::query()->firstOrCreate(
                [
                    'connection_id' => $this->connection->getId(),
                    'resource_type' => $resource->type,
                    'resource_key' => $resource->key,
                ],
                [
                    'apps_id' => $this->connection->apps_id,
                    'companies_id' => $this->connection->companies_id,
                    'external_id' => $resource->externalId,
                    'expected_value' => $resource->expectedValue,
                    'next_attempt_at' => now(),
                ]
            );
        }
    }

    /**
     * The default moves to another live account of the same integration — the one the tenant just
     * edited — and never to another provider. With none left, the channel has no default rather
     * than a dead one.
     */
    private function moveChannelDefaults(): void
    {
        $replacement = Connection::query()
            ->live()
            ->where('integrations_company_id', $this->connection->integrations_company_id)
            ->whereKeyNot($this->connection->getKey())
            ->orderBy('account_key')
            ->first();

        $defaults = ChannelDefault::query()->where('connection_id', $this->connection->getId());

        $replacement !== null
            ? $defaults->update(['connection_id' => $replacement->getId()])
            : $defaults->delete();
    }
}
