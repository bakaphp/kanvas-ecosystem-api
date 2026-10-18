<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\DataTransferObject;

use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;

/**
 * Where an inbound message belongs, and whether anything may answer it automatically. The message
 * is filed either way; only the automatic answer depends on this.
 */
final readonly class InboundRoute
{
    public const string ROUTED = 'routed';

    // Nobody claims the identity: a number the tenant had before provisioning, or one it did not
    // select. It reached the tenant's own authenticated receiver, so it keeps answering the way it
    // always has — turning that off is a selection, not a side effect of a deploy.
    public const string NOT_PROVISIONED = 'not_provisioned';

    // Claimed by another workspace: filed for the record, never answered from here.
    public const string UNROUTED = 'unrouted';

    public function __construct(
        public string $outcome,
        public ChannelTypeEnum $channel,
        public ?Connection $connection = null,
        public ?string $reason = null
    ) {
    }

    public function allowsAutomaticReply(): bool
    {
        return match ($this->outcome) {
            self::ROUTED => $this->connection?->unavailableReason() === null,
            self::NOT_PROVISIONED => true,
            default => false,
        };
    }

    /**
     * Stored on the filed message, so a reply leaves through the same connection and a responder
     * queued before an Off finds a stale version and stands down.
     *
     * @return array<string, mixed>|null
     */
    public function toMessagePayload(string $thread): ?array
    {
        if ($this->connection === null) {
            return null;
        }

        return [
            'connection_id' => $this->connection->getId(),
            'channel' => $this->channel->value,
            'thread' => $thread,
            'activation_version' => $this->connection->activation_version,
        ];
    }
}
