<?php

declare(strict_types=1);

namespace Kanvas\Communication;

use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Messages\Actions\SendMessageAction;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\Operation;
use Kanvas\Communication\Messages\DataTransferObject\Recipient;
use Kanvas\Communication\Messages\DataTransferObject\Sender;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Models\Request;
use Kanvas\Communication\Support\Router;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

/**
 * The one way any domain says something to a person. Callers name who and on which channels; which
 * vendor, which number and which credentials are decided here and are not theirs to choose.
 */
final class Communication
{
    private function __construct(
        private readonly Apps $app,
        private readonly Companies $company
    ) {
    }

    public static function for(Apps $app, Companies $company): self
    {
        return new self($app, $company);
    }

    /**
     * @param list<string>|string $channels channel names, or 'all' for every channel this person can
     *                                      be reached on right now
     * @param string|null $idempotencyKey the only thing that makes two sends one; identical content
     *                                    without it is two messages
     * @param bool $sync dispatches inline, for a caller that waits on the outcome
     */
    public function send(
        People $to,
        Content|string $content,
        array|string $channels,
        ?Sender $from = null,
        ?string $idempotencyKey = null,
        ?string $tag = null,
        bool $sync = false
    ): Operation {
        $recipient = Recipient::of($to);

        return new SendMessageAction(
            app: $this->app,
            company: $this->company,
            recipient: $recipient,
            content: $this->content($content),
            routes: new Router($this->app, $this->company)->forChannels($recipient, $channels),
            sender: $from,
            idempotencyKey: $idempotencyKey,
            tag: $tag,
            sync: $sync
        )->execute();
    }

    /**
     * Answers in the conversation the message belongs to: same connection, same counterpart. It
     * never falls back to the company default — an answer from another number is a new conversation
     * to whoever receives it.
     *
     * @param Message|null $filedAs the reply as the caller already filed it — an agent's answer is
     *                              filed, and possibly held for approval, before it is sent — so the
     *                              delivery is recorded on that message instead of on a second copy
     */
    public function reply(
        Message $message,
        Content|string $content,
        ?Sender $from = null,
        ?string $idempotencyKey = null,
        ?string $tag = null,
        bool $sync = false,
        ?Message $filedAs = null
    ): Operation {
        $route = new Router($this->app, $this->company)->forConversation($message);

        if ($route === null) {
            throw new ValidationException('Message ' . $message->getId() . ' does not belong to a conversation that can be replied to.');
        }

        return new SendMessageAction(
            app: $this->app,
            company: $this->company,
            recipient: Recipient::inConversation($this->conversationPerson($message), (string) $route->address),
            content: $this->content($content),
            routes: [$route],
            sender: $from,
            idempotencyKey: $idempotencyKey,
            tag: $tag,
            replyTo: $message,
            sync: $sync,
            filedAs: $filedAs
        )->execute();
    }

    /**
     * The counterpart's latest message in a Social channel that arrived on a connection — what a
     * reply in that channel answers, so it leaves from the number the customer wrote to.
     */
    public function conversationIn(Channel $channel): ?Message
    {
        return $channel->messages()
            ->where('messages.apps_id', $this->app->getId())
            ->where('messages.companies_id', $this->company->getId())
            ->where('message->from_me', false)
            ->whereNotNull('message->communication->connection_id')
            ->orderByDesc('messages.id')
            ->first();
    }

    /**
     * Whether a send on the channel would leave now. For callers still moving over from a vendor
     * client: they keep their own path for a company that has not been set up yet.
     */
    public function isReady(ChannelTypeEnum $channel): bool
    {
        $connection = new Router($this->app, $this->company)->defaultConnection($channel);

        return $connection !== null && $connection->unavailableReason() === null;
    }

    public function operation(int $id): Operation
    {
        return new Operation(Request::getByIdFromCompanyApp($id, $this->company, $this->app));
    }

    private function content(Content|string $content): Content
    {
        return $content instanceof Content ? $content : Content::text($content);
    }

    /**
     * Conversations from before this domain are filed on the lead; the person behind it is who the
     * reply goes to. One filed on neither — a ticket, an order — has no consent record to read.
     */
    private function conversationPerson(Message $message): ?People
    {
        $entity = $message->entity();

        return match (true) {
            $entity instanceof People => $entity,
            $entity instanceof Lead => $entity->people,
            default => null,
        };
    }
}
