<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Models\BaseModel;

/**
 * The one live owner of an identity the vendor knows globally — a Twilio number on an account.
 * Inbound for that identity routes to this connection; a second workspace connecting the same
 * identity is blocked until this row is released.
 *
 * @property int $id
 * @property string $provider
 * @property string $inbound_identity
 * @property int $connection_id
 * @property int $apps_id
 * @property int $companies_id
 */
class InboundClaim extends BaseModel
{
    protected $table = 'communication_inbound_claims';

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }

    /**
     * Global on purpose: the identity is what decides the app and workspace, so nothing may scope
     * the lookup before it.
     */
    public static function forIdentity(string $provider, ChannelTypeEnum $channel, string $identity): ?self
    {
        return self::query()
            ->where('provider', $provider)
            ->where('inbound_identity', $channel->normalizeAddress($identity) ?? $identity)
            ->first();
    }
}
