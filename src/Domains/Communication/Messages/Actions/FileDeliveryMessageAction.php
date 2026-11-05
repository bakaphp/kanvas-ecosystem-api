<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\ConversationRef;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Communication\Support\TransportManager;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;

/**
 * Files an accepted delivery in the conversation it belongs to, so what a company said shows up
 * beside what it was told — in the same Social channel the provider's inbound messages land in.
 */
final class FileDeliveryMessageAction
{
    public function __construct(private readonly Delivery $delivery, private readonly Content $content)
    {
    }

    public function execute(): Message
    {
        $connection = $this->delivery->connection;
        $request = $this->delivery->request;
        $conversation = new TransportManager()
            ->for($connection->provider, $this->delivery->channel)
            ->conversation($connection, (string) $this->delivery->to_address);

        if ($request->filed_message_id !== null) {
            return $this->stampFiledMessage((int) $request->filed_message_id, $conversation->thread);
        }

        $app = $this->delivery->app;
        $company = $this->delivery->company;
        $user = Users::query()->find($request->users_id) ?? $company->user;
        $fromAgent = ($request->sender['type'] ?? null) === 'agent';

        $payload = new AiChatMessagePayload(
            content: $this->content->text,
            from_me: true,
            from_ia: $fromAgent,
            agent_id: $fromAgent ? $request->sender['id'] : null,
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

        if ($request->people !== null) {
            $message->addEntity($request->people);
        }

        $this->channelFor($conversation, $user)->addMessage($message);

        $this->delivery->forceFill(['message_id' => $message->getId()])->saveOrFail();

        return $message;
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

    /**
     * An existing channel is used as found — including its entity — because the slug is the
     * conversation's identity and re-pointing it would move history that is not this delivery's.
     */
    private function channelFor(ConversationRef $conversation, Users $user): Channel
    {
        $channel = Channel::query()
            ->fromApp($this->delivery->app)
            ->fromCompany($this->delivery->company)
            ->where('slug', $conversation->channelSlug)
            ->orderByDesc('last_message_id')
            ->first();

        if ($channel !== null) {
            return $channel;
        }

        $request = $this->delivery->request;

        return Channel::create(array_filter([
            'users_id' => $user->getId(),
            'apps_id' => $this->delivery->apps_id,
            'companies_id' => $this->delivery->companies_id,
            'slug' => $conversation->channelSlug,
            'name' => $conversation->thread,
            'description' => 'Conversation with ' . $conversation->thread,
            'entity_namespace' => $request->people_id !== null ? People::class : null,
            'entity_id' => $request->people_id,
        ]));
    }
}
