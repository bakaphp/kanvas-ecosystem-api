<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\ConversationRef;
use Kanvas\Communication\Messages\DataTransferObject\Sender;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\PeopleChannelService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;

/**
 * Puts an accepted delivery in the conversation it belongs to, so what a company said shows up
 * beside what it was told — in the same Social channel the provider's inbound messages land in.
 */
final class RecordSentMessageAction
{
    public function __construct(private readonly Delivery $delivery, private readonly Content $content)
    {
    }

    public function execute(): Message
    {
        $request = $this->delivery->request;
        $conversation = $this->delivery->conversation();

        // Filed by the caller, or by an earlier attempt of this delivery: the message is there
        // already, and a second one would show the same text twice in the conversation.
        $filedId = $request->filed_message_id ?? $this->delivery->message_id;

        if ($filedId !== null) {
            $message = $this->stampFiledMessage((int) $filedId, $conversation->thread);

            // It may still show the failure this send replaces.
            new RecordDeliveryOnMessageAction($this->delivery)->execute();

            return $message;
        }

        $app = $this->delivery->app;
        $company = $this->delivery->company;
        $user = $request->user ?? $company->user;
        $sender = Sender::fromArray($request->sender);

        $payload = new AiChatMessagePayload(
            content: $this->content->text,
            from_me: true,
            from_ia: $sender->isAgent(),
            agent_id: $sender->isAgent() ? $sender->id : null,
            message_id: $this->delivery->external_id,
            chat_jid: $conversation->thread,
        )->toArray();

        $payload['communication'] = $this->communicationPayload($conversation->thread);

        $message = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $company,
                user: $user,
                type: MessageTypeService::getOrCreate($app, $conversation->messageVerb),
                message: $payload,
                tags: array_filter([$conversation->thread, $request->tag]),
                slug: 'communication-delivery-' . $this->delivery->uuid,
            )
        )->execute();

        $lead = $request->people !== null ? $this->leadFor($request->people) : null;

        // The person first, as an inbound message is filed: an unqualified entity() reads the first link.
        if ($request->people !== null) {
            $message->addEntity($request->people);
        }

        if ($lead !== null) {
            $message->addEntity($lead);
        }

        $this->channelFor($conversation, $user)->addMessage($message);

        // The person's whole conversation, and the lead's when they have one, beside the channel it
        // went out on.
        if ($lead !== null) {
            new LeadChannelService()->recordOnLeadAndPerson($message, $lead, $user);
        } elseif ($request->people !== null) {
            new PeopleChannelService()->recordOnPerson($message, $request->people, $user);
        }

        $this->delivery->forceFill(['message_id' => $message->getId()])->saveOrFail();

        return $message;
    }

    /**
     * A send is addressed to a person; the lead it is about is the one the email names, else the
     * one the person has. Only a lead of this same person: an id from the caller is not trusted
     * to belong to them.
     */
    private function leadFor(People $people): ?Lead
    {
        $named = $this->content->email?->leadId;

        $lead = $named !== null
            ? Lead::query()
                ->fromApp($this->delivery->app)
                ->fromCompany($this->delivery->company->getId())
                ->notDeleted()
                ->where('people_id', $people->getId())
                ->find($named)
            : null;

        return $lead ?? LeadsRepository::getPeopleCurrentLead($people);
    }

    /**
     * The caller filed this message in its conversation already; what the ledger adds is the link
     * a later reply follows back to the connection, and the provider's id for it.
     */
    private function stampFiledMessage(int $messageId, string $thread): Message
    {
        $message = Message::getByIdFromCompanyApp($messageId, $this->delivery->company, $this->delivery->app);

        $payload = $message->message;
        $payload['message_id'] = $this->delivery->external_id;
        $payload['communication'] = $this->communicationPayload($thread);
        $message->message = $payload;
        $message->saveOrFail();

        $this->delivery->forceFill(['message_id' => $message->getId()])->saveOrFail();

        return $message;
    }

    /**
     * What a later reply reads to leave through the same connection.
     *
     * @return array<string, mixed>
     */
    private function communicationPayload(string $thread): array
    {
        return [
            'delivery_id' => $this->delivery->getId(),
            'connection_id' => $this->delivery->connection->getId(),
            'channel' => $this->delivery->channel->value,
            'thread' => $thread,
        ];
    }

    private function channelFor(ConversationRef $conversation, Users $user): Channel
    {
        return new FindOrCreateConversationChannelAction(
            $this->delivery->app,
            $this->delivery->company,
            $conversation->channelSlug
        )->forOutbound($conversation->thread, $user, $this->delivery->request->people_id);
    }
}
