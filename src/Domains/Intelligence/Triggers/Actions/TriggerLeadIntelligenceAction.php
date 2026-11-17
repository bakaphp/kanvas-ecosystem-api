<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Triggers\Actions;

use GuzzleHttp\Exception\ClientException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Services\LeadConfigurationService;

/**
 * Applies what a trigger means for the lead's AI mode, then tells the remote sessions that keep
 * their own copy of the mode.
 */
final class TriggerLeadIntelligenceAction
{
    public function __construct(
        private readonly Lead $lead,
        private readonly int $triggerType
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $result = new ApplyLeadAiModeAction($this->lead, $this->triggerType)->execute();
        $aiMode = $this->lead->get(new LeadConfigurationService()->getAiModeKey($this->lead));

        if ($aiMode) {
            $this->sendModeToOrchestration((string) $aiMode);
        }

        return $result;
    }

    public function sendModeToOrchestration(string $aiMode): void
    {
        $data = ['stateDelta' => ['mode' => $aiMode]];

        foreach ($this->lead->aiSession as $session) {
            $handler = $session->agent?->type?->handlerClass();
            if ($handler === null) {
                continue;
            }

            $handle = new $handler();
            if (! method_exists($handle, 'sendDataToAgent')) {
                continue;
            }

            $handle->setConfiguration(
                agent: $session->agent,
                entity: $session->entity(),
                user: $this->lead->company->getAiAgentUserOrFail(),
            );

            try {
                $handle->sendDataToAgent($session->uuid, $data);
            } catch (ClientException $e) {
                // The remote session is gone; there is no copy of the mode left to update.
                if ($e->getResponse()->getStatusCode() === 404) {
                    continue;
                }

                throw $e;
            }
        }
    }
}
