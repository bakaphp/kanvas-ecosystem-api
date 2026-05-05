<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Triggers\Actions;

use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpValueEnum;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;

class ApplyLeadAiModeAction
{
    public function __construct(
        protected Lead $lead,
        protected int $triggerType,
    ) {
    }

    public function execute(): array
    {
        $configService = new LeadConfigurationService();
        $aiModeKey = $configService->getAiModeKey($this->lead);
        $followUpKey = $configService->getFollowUpModeKey($this->lead);

        $currentMode = IntelligenceModeEnum::tryFrom((string) $this->lead->get($aiModeKey));
        if ($currentMode?->isOff()
            && ! in_array($this->triggerType, TriggersEnum::manualTriggerValues(), true)) {
            return [
                'changed' => false,
                'message' => 'Currently Lead is in OFF mode',
            ];
        }

        $previousMode = $this->lead->get($aiModeKey);
        $previousFollowUp = $this->lead->get($followUpKey);
        $this->applyTrigger();
        $currentMode = $this->lead->get($aiModeKey);
        $currentFollowUp = $this->lead->get($followUpKey);

        if ($currentMode !== $previousMode) {
            $this->logModeChangeNote($currentMode);
        }

        if ($currentFollowUp !== $previousFollowUp) {
            $this->logFollowUpChangeNote($currentFollowUp);
        }

        return [
            'changed' => $currentMode !== $previousMode,
            'mods_previous' => [
                'ai_mode' => $previousMode,
                'ai_follow_up' => $previousFollowUp,
            ],
            'mods_current' => [
                'ai_mode' => $currentMode,
                'ai_follow_up' => $currentFollowUp,
            ],
        ];
    }

    protected function applyTrigger(): void
    {
        match ($this->triggerType) {
            TriggersEnum::NEW_LEAD->value => $this->applyNewLead(),
            TriggersEnum::AI_TAKEOVER->value => null,
            TriggersEnum::HUMAN_HANDOFF->value => null,
            TriggersEnum::HUMAN_TAKEOVER->value => null,
            TriggersEnum::HANDOFF->value => null,
            TriggersEnum::MANUAL_OFF->value => $this->applyManualMode(IntelligenceModeEnum::IDLE->value),
            TriggersEnum::MANUAL_SUPPORT->value => $this->applyManualMode(IntelligenceModeEnum::SUPPORT->value),
            TriggersEnum::MANUAL_FON->value => $this->applyManualFullOn(),
            TriggersEnum::FOLLOW_UP_ON->value => $this->setFollowUp(FollowUpValueEnum::ON()),
            TriggersEnum::FOLLOW_UP_OFF->value => $this->setFollowUp(FollowUpValueEnum::OFF()),

            default => null,
        };
    }

    protected function applyManualMode(string $aiMode): void
    {
        $this->lead->set(LeadConfigurationEnum::AI_MODE_IS_MANUAL->value, true);
        $this->setMode($aiMode);
    }

    protected function applyNewLead(): void
    {
        $leadType = $this->lead->type()->first();
        $configService = new LeadConfigurationService();
        $aiModeKey = $configService->getAiModeKey($this->lead);
        $aiFollowUpKey = $configService->getFollowUpModeKey($this->lead);

        $leadTypeConfig = $leadType?->config ?? [];
        $aiModeDefaultKey = $configService->getAiModeDefaultKey($this->lead);
        $followUpDefaultKey = $configService->getFollowUpDefaultKey($this->lead);

        $aiModeValue = $leadTypeConfig[$aiModeDefaultKey] ?? $this->lead->company->get($aiModeKey);
        $followUpRawValue = $leadTypeConfig[$followUpDefaultKey] ?? $this->lead->company->get($aiFollowUpKey);

        if ($followUpRawValue !== null) {
            $this->setFollowUp(FollowUpValueEnum::from($followUpRawValue));
        }

        $this->setMode($aiModeValue);
    }

    protected function applyManualFullOn(): void
    {
        $this->lead->set(LeadConfigurationEnum::AI_MODE_IS_MANUAL->value, true);
        $this->setMode(IntelligenceModeEnum::FULL_ON->value);
        $this->setFollowUp(FollowUpValueEnum::ON());
    }

    protected function setMode(string $aiMode): void
    {
        $aiModeKey = new LeadConfigurationService()->getAiModeKey($this->lead);

        $this->lead->set($aiModeKey, $aiMode);
        $this->lead->set(LeadConfigurationEnum::AI_MODE->value, $aiMode);
    }

    protected function setFollowUp(FollowUpValueEnum $followUpValue)
    {
        $followUpKey = new LeadConfigurationService()->getFollowUpModeKey($this->lead);
        $this->lead->set($followUpKey, $followUpValue->value);
        $this->lead->set(LeadConfigurationEnum::HAS_FOLLOW_UP->value, $followUpValue->value);
    }

    protected function logModeChangeNote(string $newMode): void
    {
        $this->logSystemNote('Sally Mode set to ' . $newMode);
    }

    protected function logFollowUpChangeNote(mixed $currentFollowUp): void
    {
        $label = $currentFollowUp ? 'Follow-up enabled' : 'Follow-up disabled';
        $this->logSystemNote($label);
    }

    protected function logSystemNote(string $noteContent): void
    {
        $notesChannel = $this->lead->systemNotes;
        if (! $notesChannel) {
            return;
        }

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
