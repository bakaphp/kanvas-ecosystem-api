<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Services;

use Baka\Contracts\CompanyInterface;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;

class LeadConfigurationService
{
    public function __construct(
        private readonly bool $isV2 = false
    ) {
    }

    public function isV2Enabled(CompanyInterface $company): bool
    {
        if ((bool) $company->get('intelligence_lead_type_mode_v2')) {
            return true;
        }

        return $this->isV2;
    }

    public function isFirstFollowUpActiveDefault(Lead $lead): bool
    {
        $leadTypeConfig = $lead->type()->first()?->config ?? [];
        $value = $leadTypeConfig[$this->getFirstMessageDefaultKey($lead)] ?? null;

        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 'true';
    }

    private function getStatusSuffix(Lead $lead): string
    {
        $statusName = strtolower($lead->status()->first()?->name ?? '');

        if (str_contains($statusName, 'not') && str_contains($statusName, 'sold')) {
            return 'closed-not-sold';
        }

        if (str_contains($statusName, 'sold')) {
            return 'closed-sold';
        }

        return '';
    }

    private function findConfigKeyBySuffix(Lead $lead, string $suffix): string
    {
        $leadTypeConfig = $lead->type()->first()?->config ?? [];

        foreach (array_keys($leadTypeConfig) as $key) {
            if ($key === $suffix || str_ends_with((string) $key, '_' . $suffix)) {
                return (string) $key;
            }
        }

        return $suffix;
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

        $isContacted = (bool) $lead->get(LeadConfigurationEnum::CONTACTED->value);
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
