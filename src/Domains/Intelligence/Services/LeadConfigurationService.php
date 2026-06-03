<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Services;

use Baka\Contracts\CompanyInterface;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
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

    private function getTypePrefix(?LeadType $leadType): string
    {
        $name = strtolower($leadType?->name ?? '');

        if (str_contains($name, 'showroom')) {
            return 'showroom';
        }

        if (str_contains($name, 'phone')) {
            return 'phone';
        }

        return 'internet';
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
        $prefix = $this->getTypePrefix($lead->type()->first());

        return "{$prefix}_first_fu_active_default";
    }

    public function getAiModeDefaultKey(Lead $lead, bool $isOpen = true): string
    {
        $prefix = $this->getTypePrefix($lead->type()->first());
        $state = $isOpen ? 'open' : 'closed';

        return "{$prefix}_ai_mode_{$state}_default";
    }

    public function getFollowUpDefaultKey(Lead $lead): string
    {
        $prefix = $this->getTypePrefix($lead->type()->first());
        $statusSuffix = $this->getStatusSuffix($lead);

        if ($statusSuffix === 'closed-not-sold') {
            return "{$prefix}_con_fu_cns_default";
        }

        if ($statusSuffix === 'closed-sold') {
            return "{$prefix}_con_fu_closed-sold_default";
        }

        return "{$prefix}_con_fu_active_default";
    }

    public function getFollowUpActiveDefaultKey(Lead $lead): string
    {
        $prefix = $this->getTypePrefix($lead->type()->first());

        return "{$prefix}_con_fu_active_default";
    }

    public function getFollowUpClosedNotSoldDefaultKey(Lead $lead): string
    {
        $prefix = $this->getTypePrefix($lead->type()->first());

        return "{$prefix}_con_fu_cns_default";
    }

    public function getFollowUpClosedSoldDefaultKey(Lead $lead): string
    {
        $prefix = $this->getTypePrefix($lead->type()->first());

        return "{$prefix}_con_fu_closed-sold_default";
    }

    public function getAllDefaultKeys(Lead $lead, bool $isOpen = true): array
    {
        $leadType = $lead->type()->first();
        $leadTypeConfig = $leadType?->config ?? [];

        $aiModeKey = $this->getAiModeDefaultKey($lead, $isOpen);
        $followUpKey = $this->getFollowUpActiveDefaultKey($lead);
        $firstMessageKey = $this->getFirstMessageDefaultKey($lead);

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
        ];
    }
}
