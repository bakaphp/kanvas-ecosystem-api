<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Kanvas\Communication\Messages\DataTransferObject\InboundTurn;
use Kanvas\Communication\Messages\Exceptions\InboundNotAnsweredException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Exceptions\AgentReplySkippedException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Traits\HandlesSupportModeDelayedResponseTrait;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
use Kanvas\Intelligence\Sessions\Models\Session as SessionModel;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

/**
 * The answer to an inbound message, by the agent that owns the number or mailbox it arrived on.
 * Who answers is read off the connection, never off a rule or a company setting: a connection
 * nobody owns is filed and left alone. Whether it may be answered is the responder's to say; this
 * opens the agent's session with the counterpart and lets a support-mode lead's salesperson go first.
 */
final class RespondToInboundMessageAction
{
    use HandlesSupportModeDelayedResponseTrait;

    /**
     * @param array<string, mixed> $params `burst_text` when the turn is a closed burst
     */
    public function __construct(
        private readonly Channel $channel,
        private readonly Message $message,
        private readonly array $params = []
    ) {
    }

    /**
     * @return array<string, mixed> without a `response`, `message` says why nothing was sent, and
     *                              `reason` is its short code when the turn was skipped for good
     */
    public function execute(): array
    {
        try {
            $turn = InboundTurn::of($this->message);
            $session = $this->session($turn->agent);
        } catch (InboundNotAnsweredException $skipped) {
            return $this->skipped($skipped);
        }

        $lead = $this->message->entity(Lead::class);

        if ($lead instanceof Lead) {
            $delayedResponse = $this->handleSupportModeDelayedResponse(
                $lead,
                $this->channel,
                $this->message,
                $this->message->app,
                $turn->agent->getId(),
                [],
                null,
                $this->params,
                AgentChannelResponderAction::class,
                $session
            );

            if ($delayedResponse !== null) {
                return $delayedResponse;
            }
        }

        try {
            return new AgentChannelResponderAction(
                $this->channel,
                $this->message,
                $turn->agent,
                $session,
                $turn
            )->execute($this->params);
        } catch (AgentReplySkippedException | ValidationException $skipped) {
            return $this->skipped($skipped);
        }
    }

    /**
     * The conversation is the person's; the lead, when there is one, is context on it. A colleague
     * who wrote to the agent's own address is neither.
     */
    private function session(Agent $agent): SessionModel
    {
        $counterpart = $this->message->entity(People::class)
            ?? $this->message->entity(Lead::class)?->people
            ?? $this->message->entity(Users::class)
            ?? throw new InboundNotAnsweredException(
                'no_counterpart',
                'The message is not filed on a person, a lead or a user'
            );

        return new CreateSessionAction(
            Session::from([
                'app' => $this->message->app,
                'company' => $this->channel->company,
                'channel' => $this->channel,
                'entity_namespace' => $counterpart::class,
                'entity_id' => (string) $counterpart->getKey(),
                'canal_id' => $this->message->message['chat_jid'] ?? null,
                'user' => [
                    'name' => $counterpart instanceof People
                        ? $counterpart->getName()
                        : trim($counterpart->firstname . ' ' . $counterpart->lastname),
                    'id' => $counterpart->getId(),
                    'email' => $counterpart instanceof People
                        ? $counterpart->getEmails()->first()?->value
                        : $counterpart->email,
                ],
                'agent' => $agent,
            ])
        )->execute();
    }

    /**
     * @return array{message: string, entity: null, reason: string}
     */
    private function skipped(AgentReplySkippedException|ValidationException $skipped): array
    {
        return [
            'message' => $skipped->getMessage(),
            'entity' => null,
            // A closed set, because the message is tagged with it: what is raised below this domain
            // carries ids in its sentence and has no code of its own.
            'reason' => match (true) {
                $skipped instanceof InboundNotAnsweredException => $skipped->reason,
                $skipped instanceof AgentReplySkippedException => 'agent_skipped',
                default => 'turn_refused',
            },
        ];
    }
}
