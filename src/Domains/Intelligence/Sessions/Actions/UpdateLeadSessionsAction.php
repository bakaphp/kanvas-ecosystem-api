<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Actions;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Throwable;

class UpdateLeadSessionsAction
{
    public function __construct(
        protected Lead $lead
    ) {
    }

    public function execute(): void
    {
        // Neuron/Laravel agents keep lead sessions too; only ADK holds remote session state to push.
        $sessions = $this->lead->aiSession()
            ->with('agent.type')
            ->get()
            ->filter(fn (Session $session) => $session->agent?->isAdk() === true);

        if ($sessions->isEmpty()) {
            return;
        }

        $adkAgent = new ADKAgent();

        foreach ($sessions as $session) {
            try {
                $stateDelta = new CreateContentSessionAction($session)->execute();

                $adkAgent->updateSessionState(
                    $this->lead->app,
                    $this->lead->company,
                    (string)$session->uuid,
                    (string)$session->entity_id,
                    $stateDelta,
                );
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
