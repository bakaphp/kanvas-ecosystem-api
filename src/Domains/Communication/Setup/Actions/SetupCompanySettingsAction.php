<?php

declare(strict_types=1);

namespace Kanvas\Communication\Setup\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Messages\Enums\ConversationAgentEnum;
use Kanvas\Communication\Setup\Enums\DefaultAgentEnum;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Enums\AgentEnum;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;

/**
 * The switches that put the agents to work: AI, AI Assist, the notes channel, follow-up v2 and the
 * agent reach-out, and which agent and user each one acts as. Last of the setup, once every person
 * has a channel, so nothing fires for someone it cannot reach. A setting the company already has,
 * false included, is its own choice and is left alone.
 */
final class SetupCompanySettingsAction
{
    private const int MESSAGE_MINUTES_INTERVAL = 10;

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company
    ) {
    }

    /**
     * @return list<string> the settings it wrote
     */
    public function execute(): array
    {
        $sally = DefaultAgentEnum::SALLY->of($this->app, $this->company);
        // The reach-out opens with the company's first-message agent when it has one.
        $reachOut = DefaultAgentEnum::named($this->app, $this->company, AgentEnum::FIRST_MESSAGE_ENGAGER->value) ?? $sally;

        $defaults = [
            IntelligenceConfigurationEnum::AI_ENABLE->value => true,
            IntelligenceConfigurationEnum::AI_ASSIST_ENABLED->value => true,
            IntelligenceConfigurationEnum::AI_NOTES_CHANNEL_ENABLED->value => true,
            IntelligenceConfigurationEnum::LEAD_FOLLOW_UP_DISABLED->value => false,
            // Only leads that arrive from now on: the follow-up and the reach-out must not wake
            // up the company's whole history the day it connects a channel.
            IntelligenceConfigurationEnum::LEAD_FOLLOW_UP_START_DATE->value => now()->toDateTimeString(),
            ConversationAgentEnum::SETTING->value => $sally?->getId(),
            // Sally's own user, never the owner's: whatever runs as this user is taken for the AI.
            IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value => $sally !== null && (int) $sally->user_id !== (int) $this->company->users_id
                ? (int) $sally->user_id
                : null,
            CompanyConfigurationEnum::AGENT_REACH_OUT_DEFAULT_AGENT_ID->value => $reachOut?->getId(),
            CompanyConfigurationEnum::MESSAGE_MINUTES_INTERVAL->value => self::MESSAGE_MINUTES_INTERVAL,
        ];

        $written = [];
        foreach ($defaults as $key => $value) {
            if ($value === null || $this->company->get($key) !== null) {
                continue;
            }

            $this->company->set($key, $value);
            $written[] = $key;
        }

        // App-wide: the follow-up engine walks every active company of an app that has it on.
        if ($this->app->get(IntelligenceConfigurationEnum::USE_LEAD_FOLLOW_UP_V2->value) === null) {
            $this->app->set(IntelligenceConfigurationEnum::USE_LEAD_FOLLOW_UP_V2->value, true);
            $written[] = IntelligenceConfigurationEnum::USE_LEAD_FOLLOW_UP_V2->value;
        }

        return $written;
    }
}
