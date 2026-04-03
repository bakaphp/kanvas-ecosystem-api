<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Triggers\Workflows;

use Carbon\Carbon;
use GuzzleHttp\Exception\ClientException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class TriggerIntelligenceActivity extends KanvasActivity
{
    public $tries = 3;

    private const MANUAL_TRIGGERS = [
        TriggersEnum::MANUAL_OFF->value,
        TriggersEnum::MANUAL_SUPPORT->value,
        TriggersEnum::MANUAL_FON->value,
    ];

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($lead, $app) use ($params) {
                $triggerType = $params['trigger_type'] ?? null;
                if (! $triggerType) {
                    return $this->failWorkflow(['error' => 'Invalid trigger type']);
                }

                if ($lead->get('ai_mode') == IntelligenceModeEnum::OFF->value
                    && ! in_array($triggerType, self::MANUAL_TRIGGERS)) {
                    return ['message' => 'Currently Lead is in OFF mode'];
                }

                $previousMode = $lead->get('ai_mode');
                $this->applyTrigger($lead, $triggerType);
                $currentMode = $lead->get('ai_mode');

                $this->sendDataToOrchestration($lead, $currentMode);

                if ($currentMode !== $previousMode) {
                    $this->logModeChangeNote($lead, $app, $currentMode);
                }

                return [
                    'Trigger IA executed',
                    'mods_previous' => [
                        'ai_mode' => $previousMode,
                        'ai_follow_up' => $lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value),
                    ],
                    'mods_current' => [
                        'ai_mode' => $currentMode,
                        'ai_follow_up' => $lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value),
                    ],
                ];
            }
        );
    }

    protected function applyTrigger(Lead $lead, int $triggerType): void
    {
        match ($triggerType) {
            TriggersEnum::NEW_LEAD->value => $this->setMode(
                $lead,
                $lead->company->get(ConfigurationEnum::AI_MODE->value) ?? IntelligenceModeEnum::FULL_ON->value,
                FollowUpTypeEnum::LEAD_FOLLOW_UP
            ),
            TriggersEnum::HUMAN_HANDOFF->value,
            TriggersEnum::HUMAN_TAKEOVER->value,
            TriggersEnum::HANDOFF->value => $this->setMode(
                $lead,
                IntelligenceModeEnum::SUPPORT->value,
                FollowUpTypeEnum::NO_FOLLOW_UP
            ),
            TriggersEnum::AI_TAKEOVER->value => $this->setMode(
                $lead,
                IntelligenceModeEnum::FULL_ON->value,
                FollowUpTypeEnum::LEAD_FOLLOW_UP
            ),
            TriggersEnum::MANUAL_OFF->value => $this->setMode(
                $lead,
                IntelligenceModeEnum::OFF->value,
                FollowUpTypeEnum::NO_FOLLOW_UP
            ),
            TriggersEnum::MANUAL_SUPPORT->value => $lead->set('ai_mode', IntelligenceModeEnum::SUPPORT->value),
            TriggersEnum::MANUAL_FON->value => $this->applyManualFullOn($lead),
            default => null,
        };
    }

    protected function applyManualFullOn(Lead $lead): void
    {
        $lead->set('ai_mode', IntelligenceModeEnum::FULL_ON->value);

        $status = LeadStatus::getByName('Sold');
        $followUp = $lead->leads_status_id === $status->getId()
            ? FollowUpTypeEnum::SOLD_LEAD_FOLLOW_UP
            : FollowUpTypeEnum::LEAD_FOLLOW_UP;

        $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, $followUp->value);
    }

    protected function setMode(Lead $lead, string $aiMode, FollowUpTypeEnum $followUp): void
    {
        $lead->set('ai_mode', $aiMode);
        $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, $followUp->value);
    }

    protected function logModeChangeNote(Lead $lead, Apps $app, string $newMode): void
    {
        $notesChannel = $lead->systemNotes;
        if (! $notesChannel) {
            return;
        }

        $carbon = Carbon::now($lead->company->timezone);
        $noteContent = $carbon->format('Y-m-d H:i:s') . ' Sally Mode set to ' . $newMode;

        $messageType = new CreateMessageTypeAction(
            new MessageTypeInput(
                apps_id: $app->getId(),
                languages_id: 1,
                name: 'ai-control',
                verb: 'ai-control',
                template: '{{message}}',
                templates_plura: '{{message}}',
            )
        )->execute();

        $createMessageAction = new CreateMessageAction(
            new MessageInput(
                app: $lead->app,
                company: $lead->company,
                user: $lead->user,
                type: $messageType,
                message: [
                'content' => $noteContent,
                'from_me' => true,
            ],
            )
        );
        $createMessageAction->runWorkflow = true;
        $message = $createMessageAction->execute();
        $notesChannel->addMessage($message, $lead->user);
    }

    protected function sendDataToOrchestration(Lead $lead, string $aiMode): void
    {
        $data = ['stateDelta' => ['mode' => $aiMode]];
        foreach ($lead->aiSession as $session) {
            $handle = new $session->agent->type->handler();
            $handle->setConfiguration($session->agent, $session->entity());

            try {
                $handle->sendDataToAgent($session->uuid, $data);
            } catch (ClientException $e) {
                if ($e->getResponse()->getStatusCode() === 404) {
                    continue;
                }

                throw $e;
            }
        }
    }
}
