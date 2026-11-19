<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Orchestrator\Actions\EnsureCompanyOrchestratorAgentAction;
use Kanvas\NervousSystem\Orchestrator\Routing\Enums\RoutingTargetTypeEnum;
use Kanvas\NervousSystem\Orchestrator\Webhooks\ProcessOrchestratorSignalJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;

/**
 * Turns lead and organization routing on or off for a company's orchestrator receivers. A meeting-notes
 * integration (Read.ai) calls it when it is connected, switched, or disconnected, so a company routes
 * meetings to its CRM only once it has said where its meetings come from.
 *
 * The receiver is marked when this action wrote `destinations`. A list someone set by hand carries no
 * mark and is never touched, in either direction. Enabling provisions the orchestrator when the
 * company has none yet: the admin needs its receiver URL to point the webhook at.
 */
class SetMeetingRoutingAction
{
    public const string SET_BY_INTEGRATION = 'destinations_set_by_integration';

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly bool $enabled
    ) {
    }

    /**
     * @return int receivers changed
     */
    public function execute(): int
    {
        $receivers = $this->receivers();

        if ($receivers === [] && $this->enabled) {
            new EnsureCompanyOrchestratorAgentAction($this->app, $this->company)->execute();
            $receivers = $this->receivers();
        }

        $changed = 0;
        foreach ($receivers as $receiver) {
            if ($this->apply($receiver)) {
                $changed++;
            }
        }

        return $changed;
    }

    private function apply(ReceiverWebhook $receiver): bool
    {
        /** @var array<string, mixed> $config */
        $config = (array) ($receiver->configuration ?? []);
        $setByUs = ! empty($config[self::SET_BY_INTEGRATION]);
        $setByHand = array_key_exists(ProcessOrchestratorSignalJob::DESTINATIONS, $config) && ! $setByUs;

        if ($setByHand) {
            return false;
        }

        if ($this->enabled) {
            $config[ProcessOrchestratorSignalJob::DESTINATIONS] = array_map(
                static fn (RoutingTargetTypeEnum $type): string => $type->value,
                RoutingTargetTypeEnum::cases()
            );
            $config[self::SET_BY_INTEGRATION] = true;
        } elseif ($setByUs) {
            unset($config[ProcessOrchestratorSignalJob::DESTINATIONS], $config[self::SET_BY_INTEGRATION]);
        } else {
            return false;
        }

        $receiver->configuration = $config;
        $receiver->saveQuietly();

        return true;
    }

    /**
     * @return list<ReceiverWebhook>
     */
    private function receivers(): array
    {
        $actionIds = WorkflowAction::query()
            ->where('model_name', ProcessOrchestratorSignalJob::class)
            ->pluck('id')
            ->all();

        if ($actionIds === []) {
            return [];
        }

        return ReceiverWebhook::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereIn('action_id', $actionIds)
            ->get()
            ->all();
    }
}
