<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Middleware;

use Kanvas\Intelligence\Agents\ChatHistory\KanvasChatHistory;
use Kanvas\Intelligence\Agents\Enums\AgentMessageTypeEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message as SocialMessage;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use Override;
use Throwable;

/**
 * Neuron's rolling summary, with the two things a business needs on top: the compacted turns are
 * archived rather than erased (the conversation store's clear() archives), and the summary itself is
 * written to Social as a private `agent_summary` message so a human can see what the agent kept,
 * and a later evaluation can read how often and at what size an agent compacts.
 */
class KanvasSummarization extends Summarization
{
    public const string SUMMARY_FLAG = 'summary';

    public const string SOCIAL_MESSAGE_ID = 'social_message_id';

    /**
     * @param Users|null $fallbackAuthor the human in the conversation, who signs the Social note only
     *                                   when neither the agent nor the company has an AI user
     */
    public function __construct(
        private readonly Agent $agent,
        private readonly ?Session $session,
        private readonly ?Users $fallbackAuthor,
        private readonly string $model,
        int $maxTokens,
        int $messagesToKeep,
        ?AIProviderInterface $provider = null,
    ) {
        parent::__construct(provider: $provider, maxTokens: $maxTokens, messagesToKeep: $messagesToKeep);
    }

    /**
     * @param Message[] $messages
     */
    #[Override]
    protected function summarizeHistory(ChatHistory $chatHistory, array $messages, AIProviderInterface $provider): void
    {
        $cutoffIndex = $this->findSafeCutoffIndex($messages);

        if ($cutoffIndex === null || $cutoffIndex <= 0) {
            return;
        }

        $oldMessages = array_slice($messages, 0, $cutoffIndex);
        $recentMessages = array_slice($messages, $cutoffIndex);

        $summary = $this->generateSummary($provider, $oldMessages);

        if ($summary === null) {
            return;
        }

        $summaryMessage = new UserMessage("## Previous conversation summary:\n\n{$summary}");
        $summaryMessage->addMetadata(self::SUMMARY_FLAG, true);

        $kept = [$summaryMessage, ...$this->discountSummarizedTokens($recentMessages)];

        $social = $this->writeSocialSummary(
            $summary,
            $chatHistory,
            $oldMessages,
            $kept,
        );

        if ($social !== null) {
            $summaryMessage->addMetadata(self::SOCIAL_MESSAGE_ID, $social->getId());
        }

        $chatHistory->flushAll();

        foreach ($kept as $message) {
            $chatHistory->addMessage($message);
        }
    }

    /**
     * Never fails the turn: a summary the human cannot see is still a summary the model can use.
     *
     * @param Message[] $archived
     * @param Message[] $kept
     */
    private function writeSocialSummary(
        string $summary,
        ChatHistory $chatHistory,
        array $archived,
        array $kept
    ): ?SocialMessage {
        try {
            $tokensBefore = $chatHistory->calculateTotalUsage();
            $tokensAfter = $chatHistory instanceof KanvasChatHistory ? $chatHistory->tokensOf($kept) : null;
            $app = $this->agent->app;
            $company = $this->agent->company;
            $author = $this->agent->user ?? $company->getAiAgentUser() ?? $this->fallbackAuthor;

            if ($author === null) {
                return null;
            }

            $action = new CreateMessageAction(
                new MessageInput(
                    app: $app,
                    company: $company,
                    user: $author,
                    type: MessageTypeService::getOrCreate($app, AgentMessageTypeEnum::AGENT_SUMMARY->value),
                    message: [
                        'content' => $summary,
                        'from_me' => true,
                        'from_ia' => true,
                        'agent_id' => $this->agent->getId(),
                        'session_id' => $this->session?->uuid,
                        'thread_id' => $chatHistory->getThreadId(),
                        'model' => $this->model,
                        'from_message_id' => $archived[0]->getId(),
                        'to_message_id' => $archived[array_key_last($archived)]->getId(),
                        'archived_count' => count($archived),
                        'tokens_before' => $tokensBefore,
                        'tokens_after' => $tokensAfter,
                    ],
                    is_public: 0,
                )
            );
            $action->runWorkflow = false;
            $message = $action->execute();

            $entity = $this->session?->entity();
            if ($entity !== null) {
                $message->addEntity($entity);
            }

            $channel = $this->session?->channel;
            if ($channel instanceof Channel) {
                $channel->addMessage($message, $author);
            }

            return $message;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
