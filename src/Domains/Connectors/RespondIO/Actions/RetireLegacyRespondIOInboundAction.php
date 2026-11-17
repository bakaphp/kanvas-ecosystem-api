<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Actions;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Communication\Connections\Actions\RetireLegacyInboundAction;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Webhooks\ProcessCommunicationWebhookJob;
use Kanvas\Connectors\RespondIO\Handlers\RespondIOHandler;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Services\WorkflowActionCatalog;

/**
 * A workspace used to post to a receiver of its own, and a rule named the agent that answered.
 * Respond.io cannot be told a new URL by API, so the receiver the workspace already posts to is
 * kept and simply becomes the communication receiver. The rule goes, and the agent it named keeps
 * answering until an agent claims the workspace's number itself.
 */
final class RetireLegacyRespondIOInboundAction
{
    /**
     * The classes are deleted; these are the names the `actions` rows of a workspace not yet moved
     * still carry.
     */
    public const string RECEIVER_JOB = 'Kanvas\\Connectors\\RespondIO\\Webhooks\\ProcessRespondIOWebhookJob';

    public const string RESPONDER_ACTIVITY = 'Kanvas\\Connectors\\RespondIO\\Workflows\\AgentChannelResponderActivity';

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @return list<string> what was retired, for the setup report
     */
    public function execute(): array
    {
        // No receiver jobs: the receiver is adopted below, not switched off.
        $retired = new RetireLegacyInboundAction($this->connection, [], [self::RESPONDER_ACTIVITY])->execute(
            fn (Rule $rule): ?string => $this->handOverTo((int) ($rule->params['agent_id'] ?? 0))
                ? 'agent ' . $rule->params['agent_id'] . ' answers ' . $this->connection->account_key
                : null
        );

        return [...$retired, ...$this->adoptReceivers()];
    }

    /**
     * Only a workspace nobody answers yet, and only to an agent of this company. Written on the
     * integration row, which is where the account is read from on every later setup.
     */
    private function handOverTo(int $agentId): bool
    {
        $agent = $this->connection->candidateOwner($agentId);
        $integrationCompany = IntegrationsCompany::query()->find($this->connection->integrations_company_id);

        if ($agent === null || $integrationCompany === null) {
            return false;
        }

        $integrationCompany->config = [
            ...($integrationCompany->config ?? []),
            RespondIOHandler::LEGACY_AGENT => $agent->getId(),
        ];
        $integrationCompany->saveOrFail();
        $this->connection->handTo($agent);

        return true;
    }

    /**
     * The oldest legacy receiver is the URL in Respond.io: it takes the communication job and keeps
     * its uuid. The empty one setup made for the provider steps aside, so the adopted one is the
     * workspace's receiver from here on.
     *
     * @return list<string>
     */
    private function adoptReceivers(): array
    {
        $legacy = ReceiverWebhook::query()
            ->fromApp((int) $this->connection->apps_id)
            ->fromCompany((int) $this->connection->companies_id)
            ->notDeleted()
            ->where('is_active', true)
            ->whereHas('action', fn (Builder $action) => $action->where('model_name', self::RECEIVER_JOB))
            ->orderBy('id')
            ->get();

        if ($legacy->isEmpty()) {
            return [];
        }

        $action = new WorkflowActionCatalog()->register(ProcessCommunicationWebhookJob::class);
        $retired = [];

        ReceiverWebhook::query()
            ->fromApp((int) $this->connection->apps_id)
            ->fromCompany((int) $this->connection->companies_id)
            ->notDeleted()
            ->where('action_id', $action->getId())
            ->where('configuration->communication->provider', RespondIOHandler::slug())
            ->get()
            ->each(
                fn (ReceiverWebhook $spare) => $spare
                    ->forceFill(['is_active' => false, 'is_deleted' => 1])
                    ->saveOrFail()
            );

        foreach ($legacy as $receiver) {
            $receiver->forceFill([
                'action_id' => $action->getId(),
                'configuration' => [
                    ...($receiver->configuration ?? []),
                    'communication' => ['provider' => RespondIOHandler::slug(), 'managed' => true],
                ],
            ])->saveOrFail();

            $retired[] = 'receiver ' . $receiver->uuid . ' moved to the communication webhook';
        }

        return $retired;
    }
}
