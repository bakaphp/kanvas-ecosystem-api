<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Triggers\Actions;

use Carbon\Carbon;
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
        $aiModeKey = LeadConfigurationService::getAiModeKey($this->lead);
        $followUpKey = LeadConfigurationService::getFollowUpModeKey($this->lead);

        if ($this->lead->get($aiModeKey) == IntelligenceModeEnum::OFF->value
            && ! in_array($this->triggerType, self::MANUAL_TRIGGERS)) {
            return [
                'changed' => false,
                'message' => 'Currently Lead is in OFF mode',
            ];
        }

        $previousMode = $this->lead->get($aiModeKey);
        $this->applyTrigger();
        $currentMode = $this->lead->get($aiModeKey);

        if ($currentMode !== $previousMode) {
            $this->logModeChangeNote($currentMode);
        }

        return [
            'changed' => $currentMode !== $previousMode,
            'mods_previous' => [
                'ai_mode' => $previousMode,
                'ai_follow_up' => $this->lead->get($followUpKey),
            ],
            'mods_current' => [
                'ai_mode' => $currentMode,
                'ai_follow_up' => $this->lead->get($followUpKey),
            ],
        ];
    }

    protected function applyTrigger(): void
    {
        match ($this->triggerType) {
            TriggersEnum::NEW_LEAD->value => function () {
                $leadType = $this->lead->type()->first();
                $aiModeKey = LeadConfigurationService::getAiModeKey($this->lead);
                $aiFollowUpKey = LeadConfigurationService::getFollowUpModeKey($this->lead);

                $leadTypeConfig = $leadType?->config ?? [];
                $aiModeDefaultKey = LeadConfigurationService::getAiModeDefaultKey($this->lead);
                $followUpDefaultKey = LeadConfigurationService::getFollowUpDefaultKey($this->lead);

                $aiModeValue = $leadTypeConfig[$aiModeDefaultKey] ?? $this->lead->company->get($aiModeKey);
                $followUpRawValue = $leadTypeConfig[$followUpDefaultKey] ?? $this->lead->company->get($aiFollowUpKey);

                $followUpValue = FollowUpValueEnum::from($followUpRawValue);
                $this->setFollowUp($followUpValue);
                $this->setMode($aiModeValue);
            },
            TriggersEnum::AI_TAKEOVER->value => null,
            TriggersEnum::HUMAN_HANDOFF->value => null,
            TriggersEnum::HUMAN_TAKEOVER->value => null,
            TriggersEnum::HANDOFF->value => null,
            TriggersEnum::MANUAL_OFF->value => $this->setMode(
                IntelligenceModeEnum::OFF->value,
            ),
            TriggersEnum::MANUAL_SUPPORT->value => $this->setMode(IntelligenceModeEnum::SUPPORT->value),
            TriggersEnum::MANUAL_FON->value => $this->applyManualFullOn(),
            TriggersEnum::FOLLOW_UP_ON->value => $this->setFollowUp(FollowUpValueEnum::ON()),
            TriggersEnum::FOLLOW_UP_OFF->value => $this->setFollowUp(FollowUpValueEnum::OFF()),

            default => null,
        };
    }

    protected function applyManualFullOn(): void
    {
        $this->setMode(IntelligenceModeEnum::FULL_ON->value);
        $this->setFollowUp(FollowUpValueEnum::ON());
    }

    protected function setMode(string $aiMode): void
    {
        $aiModeKey = LeadConfigurationService::getAiModeKey($this->lead);
        $this->lead->set($aiModeKey, $aiMode);
    }

    protected function setFollowUp(FollowUpValueEnum $followUpValue)
    {
        $followUpKey = LeadConfigurationService::getFollowUpModeKey($this->lead);
        $this->lead->set($followUpKey, $followUpValue->value);
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
