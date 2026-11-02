<?php

declare(strict_types=1);

namespace Kanvas\Communication\Contracts;

use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\ConversationRef;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Social\Messages\Models\Message;

/**
 * One provider carrying one channel. It makes the vendor call and translates the answer; routing,
 * consent, the ledger and retries are decided before it is reached and are never its business.
 */
interface Transport
{
    /**
     * A refusal the provider gave is returned as a failed result. Anything thrown means the outcome
     * is not known — the request may have reached the provider — and the delivery is recorded as
     * unknown rather than retried.
     */
    public function send(Delivery $delivery, Content $content): TransportResult;

    /**
     * Asks the provider what became of a delivery it already has an id for.
     *
     * @return TransportResult|null null when the provider cannot say
     */
    public function lookup(Delivery $delivery): ?TransportResult;

    public function conversation(Connection $connection, string $address): ConversationRef;

    /**
     * The conversation a message filed before this domain existed belongs to, read from what the
     * provider put on it.
     *
     * @return ConversationRef|null null when the message is not one of this transport's
     */
    public function conversationOf(Message $message): ?ConversationRef;
}
