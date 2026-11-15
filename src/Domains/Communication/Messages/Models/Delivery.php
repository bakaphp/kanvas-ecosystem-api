<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\Actions\RecordDeliveryOnMessageAction;
use Kanvas\Communication\Messages\DataTransferObject\ConversationRef;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Models\BaseModel;
use Kanvas\Communication\Support\TransportManager;

/**
 * One channel's attempt at a request. `status` is the outcome callers see; `events_closed_at` is a
 * separate fact — whether the provider can still report on the message — because a delivered
 * message can still be read, bounced or complained about.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $request_id
 * @property int|null $connection_id
 * @property ChannelTypeEnum $channel
 * @property string|null $provider
 * @property string|null $to_address
 * @property DeliveryStatusEnum $status
 * @property string|null $external_id
 * @property string|null $error_code
 * @property string|null $error
 * @property array|null $warnings
 * @property int $activation_version
 * @property int|null $message_id
 * @property Carbon|null $accepted_at
 * @property Carbon|null $last_event_at
 * @property Carbon|null $events_closed_at
 */
class Delivery extends BaseModel
{
    use UuidTrait;

    public const string RECIPIENT_OPTED_OUT = 'recipient_opted_out';

    protected $table = 'communication_deliveries';

    protected $attributes = [
        'is_deleted' => 0,
    ];

    protected function casts(): array
    {
        return [
            'channel' => ChannelTypeEnum::class,
            'status' => DeliveryStatusEnum::class,
            'warnings' => Json::class,
            'activation_version' => 'integer',
            'attempts' => 'integer',
            'is_deleted' => 'boolean',
            'accepted_at' => 'datetime',
            'last_event_at' => 'datetime',
            'events_closed_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class, 'request_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeliveryEvent::class, 'delivery_id');
    }

    /**
     * The delivery a provider is reporting on, by the id it gave the message. The caller adds the
     * tenant it knows: the receiver's company, or only its app when one receiver serves several.
     *
     * @param list<string>|string $providers
     * @param list<string>|string $externalIds
     */
    public function scopeReportedBy(Builder $query, array|string $providers, array|string $externalIds): Builder
    {
        return $query
            ->whereIn('provider', (array) $providers)
            ->whereIn('external_id', (array) $externalIds);
    }

    /**
     * The conversation this delivery belongs to, as its provider names it: the channel its message
     * is filed on, which is the one the answer to it arrives on.
     */
    public function conversation(): ConversationRef
    {
        // Not `$this->connection`: inside a model that is Eloquent's own database connection name.
        $connection = $this->getRelationValue('connection');

        return new TransportManager()
            ->for($connection->provider, $this->channel)
            ->conversation($connection, (string) $this->to_address);
    }

    /**
     * Whether the provider took the message. Anything else is a send the caller has to report.
     */
    public function wasAccepted(): bool
    {
        return $this->status === DeliveryStatusEnum::ACCEPTED;
    }

    /**
     * Ends the delivery without a vendor call. Closed in the same write: nothing was sent, so no
     * provider event can ever arrive for it. A message already filed for it shows why.
     */
    public function finishWithoutSending(DeliveryStatusEnum $status, string $errorCode, ?string $error = null): void
    {
        $this->forceFill([
            'status' => $status,
            'error_code' => $errorCode,
            'error' => $error,
            'events_closed_at' => now(),
        ])->saveOrFail();

        new RecordDeliveryOnMessageAction($this)->execute();
    }

    /**
     * The provider refused because the recipient unsubscribed with it directly. Kept as a warning,
     * so a caller can tell an opt-out from a fault without knowing each vendor's error codes.
     */
    public function markRecipientOptedOut(): void
    {
        $this->warnings = array_values(array_unique([...($this->warnings ?? []), self::RECIPIENT_OPTED_OUT]));
        $this->saveOrFail();
    }

    public function recipientOptedOut(): bool
    {
        return in_array(self::RECIPIENT_OPTED_OUT, $this->warnings ?? [], true);
    }
}
