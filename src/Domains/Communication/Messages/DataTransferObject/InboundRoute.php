<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\DataTransferObject;

use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Companies\Models\Companies;

/**
 * Where an inbound message belongs, and whether anything may answer it automatically. $company is
 * the workspace it is filed for; null when no workspace of the app may have it, and then nothing is
 * filed at all — not a person, not a lead.
 */
final readonly class InboundRoute
{
    public const string ROUTED = 'routed';

    // Nobody claims the identity: a number the tenant had before provisioning, or one it did not
    // select. On the tenant's own authenticated receiver it keeps answering the way it always has —
    // turning that off is a selection, not a side effect of a deploy. On an app-wide receiver there
    // is no tenant to file it for.
    public const string NOT_PROVISIONED = 'not_provisioned';

    // Claimed by a workspace this receiver does not serve: never filed here, never answered.
    public const string UNROUTED = 'unrouted';

    public function __construct(
        public string $outcome,
        public ChannelTypeEnum $channel,
        public ?Companies $company,
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
