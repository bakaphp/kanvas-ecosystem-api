<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Services;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\ChatHistory\KanvasHistoryTrimmer;
use Kanvas\Intelligence\Agents\Neuron\Stores\EntityRollupMessageStore;
use Kanvas\Intelligence\Agents\Services\ModelContextWindowService;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;

/**
 * Reads through the agent's own store and fold so a summary sees exactly what the agent saw —
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

        $store = new EntityRollupMessageStore(
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
            // The rollup has no cap of its own; a long-lived lead's whole history would otherwise go to the model.
            KanvasHistoryTrimmer::make()->trim(
                KanvasHistoryTrimmer::fold($store->loadActive($lead->uuid)),
                ModelContextWindowService::MIN_HISTORY_TOKENS,
            ),
        )));
    }
}
