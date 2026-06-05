<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Outreach;

use Baka\Support\Str;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * Returns an ordered list of (channelType, recipient) pairs the agent should
 * try when reaching out to a Lead. Order comes from the company's CHANNEL_ORDER
 * config (default: ['sms', 'email']); each entry is only included if the Lead's
 * People has a matching contact value.
 *
 * Pure-logic action — no DB writes, no side effects. Unit-testable in isolation.
 */
class ResolveLeadChannelPreferencesAction
{
    /** @var list<string> Channel types this action knows how to resolve recipients for. */
    private const array SUPPORTED_CHANNELS = ['sms', 'email', 'whatsapp'];

    public function __construct(
        protected readonly Lead $lead,
    ) {
    }

    /**
     * @return list<array{channel_type: string, recipient: string}>
     */
    public function execute(): array
    {
        $cellPhone = Str::normalizePhoneNumber(
            $this->lead->people?->getCellPhones()->first()?->value ?? ''
        );
        $email = $this->lead->people?->getEmails()->first()?->value ?? '';

        $available = array_filter([
            'sms' => $cellPhone !== '' ? $cellPhone : null,
            'email' => $email !== '' ? $email : null,
            'whatsapp' => $cellPhone !== '' ? $cellPhone : null,
        ]);

        $order = (array) (
            $this->lead->company->get(CompanyConfigurationEnum::CHANNEL_ORDER->value)
            ?? ['sms', 'email']
        );

        $result = [];
        foreach ($order as $channelType) {
            if (! in_array($channelType, self::SUPPORTED_CHANNELS, true)) {
                continue;
            }
            if (! isset($available[$channelType])) {
                continue;
            }
            $result[] = [
                'channel_type' => $channelType,
                'recipient' => (string) $available[$channelType],
            ];
        }

        return $result;
    }
}
