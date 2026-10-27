<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Actions;

use Kanvas\Communication\Connections\DataTransferObject\AccountSelection;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Contracts\CommunicationProvider;
use Kanvas\Communication\Support\ProviderRegistry;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Rules\Models\Action;

/**
 * A row saved before integrations recorded their app is adopted only on positive evidence that
 * names exactly one app. No evidence is not permission: the row stays unresolved, and unusable
 * through app-scoped reads, until an operator assigns it.
 */
final class ResolveIntegrationAppAction
{
    public const string RESOLVED = 'resolved';
    public const string UNRESOLVED = 'unresolved';
    public const string CONFLICTING = 'conflicting';

    public function __construct(
        private readonly IntegrationsCompany $integrationCompany
    ) {
    }

    /**
     * @return array{outcome: string, apps: list<int>}
     */
    public function execute(): array
    {
        if ($this->integrationCompany->apps_id !== null) {
            return ['outcome' => self::RESOLVED, 'apps' => [(int) $this->integrationCompany->apps_id]];
        }

        $provider = new ProviderRegistry()->forIntegration($this->integrationCompany);
        $apps = array_values(array_unique([
            ...$this->fromConnections(),
            ...($provider !== null ? $this->fromReceivers($provider) : []),
        ]));

        if (count($apps) === 1) {
            $this->integrationCompany->forceFill(['apps_id' => $apps[0]])->saveOrFail();

            return ['outcome' => self::RESOLVED, 'apps' => $apps];
        }

        return ['outcome' => $apps === [] ? self::UNRESOLVED : self::CONFLICTING, 'apps' => $apps];
    }

    /**
     * @return list<int>
     */
    private function fromConnections(): array
    {
        return Connection::query()
            ->where('integrations_company_id', $this->integrationCompany->getId())
            ->distinct()
            ->pluck('apps_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * A receiver for this company wired to the provider's inbound job counts only when its
     * configuration names one of this row's accounts — a receiver for another number on the same
     * provider says nothing about this row.
     *
     * @param class-string<CommunicationProvider> $provider
     *
     * @return list<int>
     */
    private function fromReceivers(string $provider): array
    {
        $accounts = array_map(fn (AccountSelection $account): string => $account->key, $provider::accounts($this->integrationCompany));

        if ($accounts === []) {
            return [];
        }

        $jobs = app($provider::provisioner())->legacyReceiverJobs();
        $actionIds = Action::query()->whereIn('model_name', $jobs)->pluck('id');

        return ReceiverWebhook::query()
            ->where('companies_id', $this->integrationCompany->companies_id)
            ->whereIn('action_id', $actionIds)
            ->notDeleted()
            ->get()
            ->filter(fn (ReceiverWebhook $receiver): bool => array_intersect(
                $accounts,
                array_map('strval', array_filter(array_values($receiver->configuration ?? []), 'is_scalar'))
            ) !== [])
            ->pluck('apps_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
