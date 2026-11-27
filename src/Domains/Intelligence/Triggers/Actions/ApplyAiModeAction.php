<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Triggers\Actions;

use Exception;
use InvalidArgumentException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpValueEnum;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Kanvas\Intelligence\Support\UnrespondedLeadAgentMessageCache;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;

use function Sentry\captureException;

/**
 * What an AI trigger means for the AI mode. The mode is the person's as much as the lead's: a
 * conversation is held with the person, so applied to either one it lands on the person and on
 * their active lead.
 */
class ApplyAiModeAction
{
    protected ?Lead $lead;
    protected ?People $people;

    /**
     * @param Lead|People $entity a person stands for their active lead; one with no active lead
     *                            keeps the mode on their own record
     */
    public function __construct(
        Lead|People $entity,
        protected int $triggerType,
    ) {
        $this->lead = $entity instanceof Lead ? $entity : LeadsRepository::getPeopleActiveLead($entity);
        $this->people = $entity instanceof People ? $entity : $entity->people;
    }

    public function execute(): array
    {
        if ($this->lead === null) {
            return $this->applyToPeopleWithoutLead();
        }

        $configService = new LeadConfigurationService();
        $aiModeKey = $configService->getAiModeKey($this->lead);
        $followUpKey = $configService->getFollowUpModeKey($this->lead);

        $previousMode = $this->lead->get($aiModeKey);
        $previousFollowUp = $this->lead->get($followUpKey);
        $this->applyTrigger();
        $currentMode = $this->lead->get($aiModeKey);
        $currentFollowUp = $this->lead->get($followUpKey);

        $this->replicateOnPeople();

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

    /**
     * Only a mode a person chose by hand is kept: every other trigger reads a lead (its type's
     * default, its status) and there is none. Follow-up is the lead's alone.
     */
    protected function applyToPeopleWithoutLead(): array
    {
        $previousMode = $this->people->get(ConfigurationEnum::AI_MODE->value);
        $manualMode = match ($this->triggerType) {
            TriggersEnum::MANUAL_OFF->value => IntelligenceModeEnum::IDLE,
            TriggersEnum::MANUAL_SUPPORT->value => IntelligenceModeEnum::SUPPORT,
            TriggersEnum::MANUAL_FON->value => IntelligenceModeEnum::FULL_ON,
            default => null,
        };

        if ($manualMode !== null) {
            $this->people->set(LeadConfigurationEnum::AI_MODE_IS_MANUAL->value, true);
            $this->people->set(ConfigurationEnum::AI_MODE->value, $manualMode->value);
        }

        $currentMode = $manualMode?->value ?? $previousMode;

        return [
            'changed' => $currentMode !== $previousMode,
            'mods_previous' => [
                'ai_mode' => $previousMode,
                'ai_follow_up' => null,
            ],
            'mods_current' => [
                'ai_mode' => $currentMode,
                'ai_follow_up' => null,
            ],
        ];
    }

    /**
     * The person carries the mode of their active lead and its manual pin, never an older lead's:
     * a closed opportunity does not speak for the person once a newer one is open.
     */
    protected function replicateOnPeople(): void
    {
        if ($this->people === null) {
            return;
        }

        $activeLead = LeadsRepository::getPeopleActiveLead($this->people);

        if ($activeLead !== null && ! $activeLead->is($this->lead)) {
            return;
        }

        $aiMode = $this->lead->get(new LeadConfigurationService()->getAiModeKey($this->lead));

        if ($aiMode !== null && $this->people->get(ConfigurationEnum::AI_MODE->value) !== $aiMode) {
            $this->people->set(ConfigurationEnum::AI_MODE->value, $aiMode);
        }

        // The pin is mirrored both ways: left standing from an older lead, it would silence the
        // person on a new lead nobody switched off.
        $isPinned = (bool) $this->lead->get(LeadConfigurationEnum::AI_MODE_IS_MANUAL->value);

        if ((bool) $this->people->get(LeadConfigurationEnum::AI_MODE_IS_MANUAL->value) !== $isPinned) {
            $this->people->set(LeadConfigurationEnum::AI_MODE_IS_MANUAL->value, $isPinned);
        }
    }

    protected function applyTrigger(): void
    {
        match ($this->triggerType) {
            TriggersEnum::NEW_LEAD->value => $this->applyNewLead(),
            TriggersEnum::AI_TAKEOVER->value => null,
            TriggersEnum::HUMAN_HANDOFF->value => null,
            TriggersEnum::HUMAN_TAKEOVER->value => null,
            TriggersEnum::HANDOFF->value => null,
            TriggersEnum::MANUAL_OFF->value => $this->applyManualOff(),
            TriggersEnum::MANUAL_SUPPORT->value => $this->applyManualMode(IntelligenceModeEnum::SUPPORT->value),
            TriggersEnum::MANUAL_FON->value => $this->applyManualFullOn(),
            TriggersEnum::FOLLOW_UP_ON->value => $this->applyManualFollowUpOn(),
            TriggersEnum::FOLLOW_UP_OFF->value => $this->setFollowUp(FollowUpValueEnum::OFF()),
            TriggersEnum::CLOSE_LEAD->value => $this->closeLead(),
            TriggersEnum::SOLD_LEAD->value => $this->soldLead(),
            default => null,
        };
    }

    protected function applyManualMode(string $aiMode): void
    {
        $this->lead->set(LeadConfigurationEnum::AI_MODE_IS_MANUAL->value, true);
        $this->setMode($aiMode);
    }

    public function closeLead(): void
    {
        $leadType = $this->lead->type()->first();
        $configService = new LeadConfigurationService();
        $aiFollowUpKey = $configService->getFollowUpModeKey($this->lead);

        $leadTypeConfig = $leadType?->config ?? [];
        $followUpDefaultKey = $configService->getFollowUpClosedNotSoldDefaultKey($this->lead);

        $followUpRawValue = $leadTypeConfig[$followUpDefaultKey] ?? $this->lead->company->get($aiFollowUpKey);

        if ($followUpRawValue !== null) {
            $this->setFollowUp(FollowUpValueEnum::from($followUpRawValue));
        }
    }

    public function soldLead(): void
    {
        $leadType = $this->lead->type()->first();
        $configService = new LeadConfigurationService();
        $aiFollowUpKey = $configService->getFollowUpModeKey($this->lead);

        $leadTypeConfig = $leadType?->config ?? [];
        $followUpDefaultKey = $configService->getFollowUpClosedSoldDefaultKey($this->lead);

        $followUpRawValue = $leadTypeConfig[$followUpDefaultKey] ?? $this->lead->company->get($aiFollowUpKey);

        if ($followUpRawValue !== null) {
            $this->setFollowUp(FollowUpValueEnum::from($followUpRawValue));
        }
    }

    protected function applyNewLead(): void
    {
        $configService = new LeadConfigurationService();
        $aiModeKey = $configService->getAiModeKey($this->lead);

        if ($this->lead->get($aiModeKey) === IntelligenceModeEnum::IDLE->value) {
            return;
        }

        $leadType = $this->lead->type()->first();
        $aiFollowUpKey = $configService->getFollowUpModeKey($this->lead);

        $isOpen = $this->isCompanyWithinWorkingHours();

        $leadTypeConfig = $leadType?->config ?? [];
        $aiModeDefaultKey = $configService->getAiModeDefaultKey($this->lead, $isOpen);
        $followUpDefaultKey = $configService->getFollowUpActiveDefaultKey($this->lead);

        $aiModeValue = $leadTypeConfig[$aiModeDefaultKey] ?? $this->lead->company->get($aiModeKey) ?? null;
        $followUpRawValue = $leadTypeConfig[$followUpDefaultKey] ?? $this->lead->company->get($aiFollowUpKey);

        if ($followUpRawValue !== null) {
            $this->setFollowUp(FollowUpValueEnum::from($followUpRawValue));
        }

        if ($aiModeValue !== null) {
            $this->setMode($aiModeValue);
        }
        $this->lead->set('first_followup', $configService->getAllDefaultKeys($this->lead, $isOpen));

        match ($configService->getStatusSuffix($this->lead)) {
            'closed-sold' => $this->soldLead(),
            'closed-not-sold' => $this->closeLead(),
            default => null,
        };
    }

    protected function isCompanyWithinWorkingHours(): bool
    {
        try {
            return $this->lead->company->isWithinWorkingHours(now());
        } catch (InvalidArgumentException $e) {
            return true;
        }
    }

    protected function applyManualOff(): void
    {
        $this->applyManualMode(IntelligenceModeEnum::IDLE->value);
        $this->setFollowUp(FollowUpValueEnum::OFF());
    }

    protected function applyManualFullOn(): void
    {
        $this->lead->set(LeadConfigurationEnum::AI_MODE_IS_MANUAL->value, true);
        $this->setMode(IntelligenceModeEnum::FULL_ON->value);
        $this->applyManualFollowUpOn();

        try {
            foreach ($this->lead->socialChannels as $channel) {
                UnrespondedLeadAgentMessageCache::clear($this->lead, $channel);
            }
        } catch (Exception $e) {
            captureException($e);
        }
    }

    protected function setMode(string $aiMode): void
    {
        $aiModeKey = new LeadConfigurationService()->getAiModeKey($this->lead);

        $this->lead->set($aiModeKey, $aiMode);
        // $this->lead->set(LeadConfigurationEnum::AI_MODE->value, $aiMode);
    }

    /**
     * A person turning follow-up on pins it, like a manual AI mode: the company's follow-up start
     * date and the lead type's default are admin config for the automatic follow-up and no longer
     * hold the lead back (LeadFollowUpEligibilityService). Turning it off, by hand or not, clears it.
     */
    protected function applyManualFollowUpOn(): void
    {
        $this->setFollowUp(FollowUpValueEnum::ON());
        new LeadConfigurationService()->setManualFollowUp($this->lead, true);
    }

    protected function setFollowUp(FollowUpValueEnum $followUpValue)
    {
        $configService = new LeadConfigurationService();
        $this->lead->set($configService->getFollowUpModeKey($this->lead), $followUpValue->value);
        $this->lead->set(LeadConfigurationEnum::HAS_FOLLOW_UP->value, $followUpValue->value);

        if ($followUpValue->equals(FollowUpValueEnum::OFF())) {
            $configService->setManualFollowUp($this->lead, false);
        }
    }

    protected function logModeChangeNote(string $newMode): void
    {
        $agentName = $this->lead->aiSession()->with('agent.user')->first()?->agent?->user?->firstname ?? 'Sally';

        $this->logSystemNote($agentName . ' Mode set to ' . $newMode);
    }

    protected function logFollowUpChangeNote(mixed $currentFollowUp): void
    {
        $label = $currentFollowUp ? 'Follow-up enabled' : 'Follow-up disabled';
        $this->logSystemNote($label);
    }

    protected function logSystemNote(string $noteContent): void
    {
        new RecordLeadNoteAction($this->lead)->recordAiControl($noteContent);
    }
}
