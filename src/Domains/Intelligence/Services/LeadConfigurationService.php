<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Services;

use Baka\Contracts\CompanyInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;

class LeadConfigurationService
{
    public function getStatusSuffix(Lead $lead): string
    {
        if ($lead->closeNotSold()) {
            return 'closed-not-sold';
        }

        if ($lead->closeSold()) {
            return 'closed-sold';
        }

        return '';
    }

    private function findConfigKeyBySuffix(Lead $lead, string $suffix): string
    {
        return $this->configKeyBySuffix($lead->type()->first()?->config ?? [], $suffix);
    }

    /**
     * @param array<string, mixed> $leadTypeConfig
     */
    private function configKeyBySuffix(array $leadTypeConfig, string $suffix): string
    {
        foreach (array_keys($leadTypeConfig) as $key) {
            if ($key === $suffix || str_ends_with((string) $key, '_' . $suffix)) {
                return (string) $key;
            }
        }

        return $suffix;
    }

    /**
     * Stricter than allowsContinuedFollowUp(), where a missing key allows: this asks whether the
     * lead type explicitly turned follow-up on for open leads.
     */
    public function hasContinuedFollowUpActive(LeadType $leadType): bool
    {
        $config = $leadType->config ?? [];
        $key = $this->configKeyBySuffix($config, 'con_fu_active_default');

        return filter_var($config[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public function getAiModeKey(Lead $lead): string
    {
        return 'ai_mode';
    }

    public function getFollowUpModeKey(Lead $lead): string
    {
        return IntelligenceModeEnum::AI_FOLLOW_UP->value;
    }

    public function getFirstMessageDefaultKey(Lead $lead): string
    {
        return $this->findConfigKeyBySuffix($lead, 'first_fu_active_default');
    }

    public function getAiModeDefaultKey(Lead $lead, bool $isOpen = true): string
    {
        $suffix = $isOpen ? 'ai_mode_open_default' : 'ai_mode_closed_default';

        return $this->findConfigKeyBySuffix($lead, $suffix);
    }

    public function getFollowUpDefaultKey(Lead $lead): string
    {
        $statusSuffix = $this->getStatusSuffix($lead);

        if ($statusSuffix === 'closed-not-sold') {
            return $this->findConfigKeyBySuffix($lead, 'con_fu_cns_default');
        }

        if ($statusSuffix === 'closed-sold') {
            return $this->findConfigKeyBySuffix($lead, 'con_fu_closed-sold_default');
        }

        return $this->findConfigKeyBySuffix($lead, 'con_fu_active_default');
    }

    public function allowsContinuedFollowUp(Lead $lead): bool
    {
        $config = $lead->type()->first()?->config ?? [];
        if ($config === []) {
            return true;
        }

        $key = $lead->isOpen()
            ? $this->getFollowUpActiveDefaultKey($lead)
            : $this->getFollowUpDefaultKey($lead);

        // Legacy defaults are optional; only an explicit false value vetoes v2.
        return ! array_key_exists($key, $config)
            || filter_var($config[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
    }

    /**
     * An unparseable value is treated as unset so a typo in the setting never silences every lead.
     */
    public function getFollowUpStartDate(CompanyInterface $company): ?Carbon
    {
        $value = $company->get(ConfigurationEnum::LEAD_FOLLOW_UP_START_DATE->value);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    public function isCreatedBeforeFollowUpStartDate(Lead $lead): bool
    {
        $startDate = $this->getFollowUpStartDate($lead->company);

        return $startDate !== null && $startDate->greaterThan($lead->created_at);
    }

    /**
     * A person turned follow-up on for this lead by hand (ApplyLeadAiModeAction pins it), so the
     * automatic follow-up's admin config — start date, lead-type default — no longer applies to it.
     */
    public function hasManualFollowUp(Lead $lead): bool
    {
        return (bool) $lead->get(LeadConfigurationEnum::AI_FOLLOW_UP_IS_MANUAL->value)
            && $lead->isAiFollowUpEnabled();
    }

    /**
     * Only a person's "on" pins; any "off" clears it, so the row exists only while the pin is
     * active and the fan-out can find pinned leads by name alone.
     */
    public function setManualFollowUp(Lead $lead, bool $enabled): void
    {
        if ($enabled) {
            $lead->set(LeadConfigurationEnum::AI_FOLLOW_UP_IS_MANUAL->value, true);

            return;
        }

        $lead->del(LeadConfigurationEnum::AI_FOLLOW_UP_IS_MANUAL->value);
    }

    public function getFollowUpActiveDefaultKey(Lead $lead): string
    {
        return $this->findConfigKeyBySuffix($lead, 'con_fu_active_default');
    }

    public function getFollowUpClosedNotSoldDefaultKey(Lead $lead): string
    {
        return $this->findConfigKeyBySuffix($lead, 'con_fu_cns_default');
    }

    public function getFollowUpClosedSoldDefaultKey(Lead $lead): string
    {
        return $this->findConfigKeyBySuffix($lead, 'con_fu_closed-sold_default');
    }

    public function getAllDefaultKeys(Lead $lead, bool $isOpen = true): array
    {
        $leadType = $lead->type()->first();
        $leadTypeConfig = $leadType?->config ?? [];

        $aiModeKey = $this->getAiModeDefaultKey($lead, $isOpen);
        $followUpKey = $this->getFollowUpActiveDefaultKey($lead);
        $firstMessageKey = $this->getFirstMessageDefaultKey($lead);

        $isContacted = $lead->hasBeenContacted();
        $firstFollowUpActive = (bool) ($leadTypeConfig[$firstMessageKey] ?? false);

        return [
            'ai_mode' => [
                'key' => $aiModeKey,
                'value' => $leadTypeConfig[$aiModeKey] ?? null,
            ],
            'follow_up' => [
                'key' => $followUpKey,
                'value' => $leadTypeConfig[$followUpKey] ?? null,
            ],
            'first_message' => [
                'key' => $firstMessageKey,
                'value' => $leadTypeConfig[$firstMessageKey] ?? null,
            ],
            'is_contacted' => $isContacted,
            'should_send_first_followup' => ! $isContacted && $firstFollowUpActive,
        ];
    }
}
