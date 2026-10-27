<?php

declare(strict_types=1);

namespace Kanvas\Communication\Support;

use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\ConversationAgentEnum;
use Kanvas\Communication\Messages\Exceptions\ConversationAgentNotFoundException;
use Kanvas\Communication\Setup\Enums\DefaultAgentEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Which agent, if any, answers inbound on a channel. Never an onboarding input: communication works
 * without an agent, and the answer only changes when someone sets one of these on purpose.
 */
final class AutoReplyPolicy
{
    public const string SETTING = 'communication_auto_reply_agent_id';

    /**
     * The agent the company's conversations are answered by: the one its setting names, else its
     * Sally. A setting naming an agent the company does not have is a fault, not a reason to pick
     * another one.
     *
     * @throws ConversationAgentNotFoundException
     */
    public function conversationAgent(Apps $app, Companies $company): Agent
    {
        $agentId = $company->get(ConversationAgentEnum::SETTING->value);

        if (! empty($agentId)) {
            return $this->agentWithId($app, $company, (int) $agentId)
                ?? throw new ConversationAgentNotFoundException(sprintf(
                    'Company %d sets %s to agent %s, which it does not have.',
                    $company->getId(),
                    ConversationAgentEnum::SETTING->value,
                    $agentId
                ));
        }

        return DefaultAgentEnum::SALLY->of($app, $company)
            ?? throw new ConversationAgentNotFoundException(sprintf(
                'Company %d has no %s setting and no agent named %s.',
                $company->getId(),
                ConversationAgentEnum::SETTING->value,
                ConversationAgentEnum::DEFAULT_NAME->value
            ));
    }

    public function agentFor(Apps $app, Companies $company, ChannelTypeEnum $channel): ?Agent
    {
        $agentId = $company->get(self::SETTING . '_' . $channel->value)
            ?? $company->get(self::SETTING)
            ?? $app->get(self::SETTING);

        if (empty($agentId)) {
            return null;
        }

        return $this->agentWithId($app, $company, (int) $agentId);
    }

    private function agentWithId(Apps $app, Companies $company, int $agentId): ?Agent
    {
        return Agent::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->whereKey($agentId)
            ->first();
    }
}
