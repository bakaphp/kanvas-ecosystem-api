<?php

declare(strict_types=1);

namespace Kanvas\Communication\Setup\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Messages\Enums\ConversationAgentEnum;
use Kanvas\Communication\Setup\Enums\DefaultAgentEnum;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;

/**
 * The switches that put the agents to work: AI, AI Assist, the notes channel, follow-up v2, the
 * user the AI acts as, and the reach-out on the first-message agent itself. Run once every person
 * has a channel, so nothing fires for someone it cannot reach. A setting the company already has,
 * false included, is its own choice and is left alone.
 */
final class SetupCompanySettingsAction
{
    public const string REACH_OUT = 'agent.reach_out.new_leads';

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

        if ($this->switchOnReachOut()) {
            $written[] = self::REACH_OUT;
        }

        // App-wide: the follow-up engine walks every active company of an app that has it on.
        if ($this->app->get(IntelligenceConfigurationEnum::USE_LEAD_FOLLOW_UP_V2->value) === null) {
            $this->app->set(IntelligenceConfigurationEnum::USE_LEAD_FOLLOW_UP_V2->value, true);
            $written[] = IntelligenceConfigurationEnum::USE_LEAD_FOLLOW_UP_V2->value;
        }

        return $written;
    }

    /**
     * Reaching out is the agent's own switch. Only a company where no agent has it yet gets it, on
     * its first-message agent: one whose agent was switched off by hand already has a row saying so.
     */
    private function switchOnReachOut(): bool
    {
        $firstMessage = DefaultAgentEnum::FIRST_MESSAGE_ENGAGER->of($this->app, $this->company);

        if ($firstMessage === null || isset($firstMessage->reach_out_config['new_leads'])) {
            return false;
        }

        if (Agent::forNewLeads($this->app, $this->company) !== null) {
            return false;
        }

        $firstMessage->switchReachOut();

        return true;
    }
}
