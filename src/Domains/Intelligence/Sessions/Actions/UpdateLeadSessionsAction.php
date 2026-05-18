<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Actions;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use Throwable;

class UpdateLeadSessionsAction
{
    public function __construct(
        protected Lead $lead
    ) {
    }

    public function execute(): void
    {
        $sessions = $this->lead->aiSession()->get();

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
