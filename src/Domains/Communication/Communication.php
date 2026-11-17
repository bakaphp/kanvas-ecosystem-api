<?php

declare(strict_types=1);

namespace Kanvas\Communication;

use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Models\Connection;
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
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Models\Message;

/**
 * The one way any domain says something to a person. Callers name who and on which channels; which
 * vendor, which number and which credentials are decided here and are not theirs to choose.
 */
final class Communication
{
    public const string OPT_OUT_CONFIRMATION = 'opt_out_confirmation';

    private ?Router $router = null;

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
     * @param string|null $toAddress the address to reach the person on, when it is not their first
     *                               contact of that kind
     * @param string|null $fromAccount the company's number or mailbox to send from; without it the
     *                                 one the speaking agent owns, else the channel's default
     * @param bool $file false when the caller files the message itself and links the delivery to
     *                   it afterwards; only for a sync send, whose outcome the caller holds
     */
    public function send(
        People $to,
        Content|string $content,
        array|string $channels,
        ?Sender $from = null,
        ?string $idempotencyKey = null,
        ?string $tag = null,
        bool $sync = false,
        ?string $toAddress = null,
        ?string $fromAccount = null,
        bool $file = true
    ): Operation {
        $recipient = $this->recipient($to, $toAddress);

        return new SendMessageAction(
            app: $this->app,
            company: $this->company,
            recipient: $recipient,
            content: $this->content($content),
            routes: $this->router()->forChannels(
                $recipient,
                $channels,
                $from,
                $fromAccount
            ),
            sender: $from,
            idempotencyKey: $idempotencyKey,
            tag: $tag,
            sync: $sync,
            fileMessage: $file
        )->execute();
    }

    /**
     * The one message a person who just asked not to be contacted still gets: that they were
     * unsubscribed. Every other send to them is refused, which is why this is its own door and not
     * a flag on `send()`. Tagged on the request, so each use can be found.
     */
    public function confirmOptOut(
        People $to,
        Content|string $content,
        ChannelTypeEnum $channel,
        ?string $toAddress = null,
        ?string $fromAccount = null
    ): Operation {
        $recipient = $this->recipient($to, $toAddress);

        return new SendMessageAction(
            app: $this->app,
            company: $this->company,
            recipient: $recipient,
            content: $this->content($content),
            routes: $this->router()->forChannels(
                $recipient,
                [$channel->value],
                fromAccount: $fromAccount
            ),
            sender: Sender::system(),
            tag: self::OPT_OUT_CONFIRMATION,
            sync: true,
            honorsConsent: false
        )->execute();
    }

    /**
     * The connection a send on this channel would leave through, for a caller that has to know
     * before it sends.
     */
    public function connectionFor(
        ChannelTypeEnum $channel,
        ?Sender $from = null,
        ?string $fromAccount = null
    ): ?Connection {
        return $this->router()->connectionFor($channel, $from, $fromAccount);
    }

    /**
     * Whether a message from the agent on that channel leaves from the agent's own address. From
     * its own address an agent writes as a person does; from any other it sends the company's
     * message, in the company's template.
     */
    public function speaksFromOwnAddress(ChannelTypeEnum $channel, ?Agent $agent): bool
    {
        return $agent !== null
            && $this->connectionFor($channel, Sender::agent($agent))?->isPersonalAddressOf($agent) === true;
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
        $route = $this->router()->forConversation($message);

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
     * Whether a send on the channel would leave now. For callers still moving over from a vendor
     * client: they keep their own path for a company that has not been set up yet.
     */
    public function isReady(ChannelTypeEnum $channel): bool
    {
        $connection = $this->router()->defaultConnection($channel);

        return $connection !== null && $connection->unavailableReason() === null;
    }

    public function operation(int $id): Operation
    {
        return new Operation(Request::getByIdFromCompanyApp($id, $this->company, $this->app));
    }

    private function router(): Router
    {
        return $this->router ??= new Router($this->app, $this->company);
    }

    private function content(Content|string $content): Content
    {
        return $content instanceof Content ? $content : Content::text($content);
    }

    private function recipient(People $to, ?string $toAddress): Recipient
    {
        return $toAddress !== null ? Recipient::inConversation($to, $toAddress) : Recipient::of($to);
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
