<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Services;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\SalesAssistKanvasMessageHistory;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;

/**
 * Reads through the agent's own history loader so a summary sees exactly what the agent saw —
 * every channel for the lead, internal verbs (and earlier summaries) already filtered out.
 */
class LeadConversationTranscriptService
{
    /**
     * @return list<string>
     */
    public static function lines(Lead $lead): array
    {
        $user = $lead->company->getAiAgentUser() ?? $lead->user;

        if ($user === null) {
            return [];
        }

        $history = new SalesAssistKanvasMessageHistory(
            app: $lead->app,
            company: $lead->company,
            user: $user,
            entity: $lead,
        );

        return array_values(array_filter(array_map(
            static function (Message $message): ?string {
                $content = trim($message->getContent() ?? '');

                if ($content === '') {
                    return null;
                }

                return $message->getRole() === MessageRole::ASSISTANT->value
                    ? "[Agent] {$content}"
                    : $content;
            },
            $history->getMessages(),
        )));
    }
}
