<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Triggers\Actions;

use Carbon\Carbon;
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

class ApplyLeadAiModeAction
{
    private const MANUAL_TRIGGERS = [
        TriggersEnum::MANUAL_OFF->value,
        TriggersEnum::MANUAL_SUPPORT->value,
        TriggersEnum::MANUAL_FON->value,
    ];

    public function __construct(
        protected Lead $lead,
        protected int $triggerType,
    ) {
    }

    public function execute(): array
    {
        if ($this->lead->get('ai_mode') == IntelligenceModeEnum::OFF->value
            && ! in_array($this->triggerType, self::MANUAL_TRIGGERS)) {
            return [
                'changed' => false,
                'message' => 'Currently Lead is in OFF mode',
            ];
        }

        $previousMode = $this->lead->get('ai_mode');
        $this->applyTrigger();
        $currentMode = $this->lead->get('ai_mode');

        if ($currentMode !== $previousMode) {
            $this->logModeChangeNote($currentMode);
        }

        return [
            'changed' => $currentMode !== $previousMode,
            'mods_previous' => [
                'ai_mode' => $previousMode,
                'ai_follow_up' => $this->lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value),
            ],
            'mods_current' => [
                'ai_mode' => $currentMode,
                'ai_follow_up' => $this->lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value),
            ],
        ];
    }

    protected function applyTrigger(): void
    {
        match ($this->triggerType) {
            TriggersEnum::NEW_LEAD->value => $this->setMode(
                $this->lead->company->get(ConfigurationEnum::AI_MODE->value) ?? IntelligenceModeEnum::FULL_ON->value,
                FollowUpTypeEnum::LEAD_FOLLOW_UP
            ),
            TriggersEnum::HUMAN_HANDOFF->value,
            TriggersEnum::HUMAN_TAKEOVER->value,
            TriggersEnum::HANDOFF->value => $this->setMode(
                IntelligenceModeEnum::SUPPORT->value,
                FollowUpTypeEnum::NO_FOLLOW_UP
            ),
            TriggersEnum::AI_TAKEOVER->value => $this->setMode(
                IntelligenceModeEnum::FULL_ON->value,
                FollowUpTypeEnum::LEAD_FOLLOW_UP
            ),
            TriggersEnum::MANUAL_OFF->value => $this->setMode(
                IntelligenceModeEnum::OFF->value,
                FollowUpTypeEnum::NO_FOLLOW_UP
            ),
            TriggersEnum::MANUAL_SUPPORT->value => $this->lead->set('ai_mode', IntelligenceModeEnum::SUPPORT->value),
            TriggersEnum::MANUAL_FON->value => $this->applyManualFullOn(),
            default => null,
        };
    }

    protected function applyManualFullOn(): void
    {
        $this->lead->set('ai_mode', IntelligenceModeEnum::FULL_ON->value);

        $status = LeadStatus::getByName('Sold');
        $followUp = $this->lead->leads_status_id === $status->getId()
            ? FollowUpTypeEnum::SOLD_LEAD_FOLLOW_UP
            : FollowUpTypeEnum::LEAD_FOLLOW_UP;

        $this->lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, $followUp->value);
    }

    protected function setMode(string $aiMode, FollowUpTypeEnum $followUp): void
    {
        $this->lead->set('ai_mode', $aiMode);
        $this->lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, $followUp->value);
    }

    protected function logModeChangeNote(string $newMode): void
    {
        $notesChannel = $this->lead->systemNotes;
        if (! $notesChannel) {
            return;
        }

        $carbon = Carbon::now($this->lead->company->timezone);
        $noteContent = $carbon->format('Y-m-d H:i:s') . ' Sally Mode set to ' . $newMode;

        $messageType = new CreateMessageTypeAction(
            new MessageTypeInput(
                apps_id: $this->lead->app->getId(),
                languages_id: 1,
                name: 'ai-control',
                verb: 'ai-control',
                template: '{{message}}',
                templates_plura: '{{message}}',
            )
        )->execute();

        $createMessageAction = new CreateMessageAction(
            new MessageInput(
                app: $this->lead->app,
                company: $this->lead->company,
                user: $this->lead->user,
                type: $messageType,
                message: [
                    'content' => $noteContent,
                    'from_me' => true,
                ],
            )
        );
        $createMessageAction->runWorkflow = true;
        $message = $createMessageAction->execute();
        $notesChannel->addMessage($message, $this->lead->user);
    }
}
