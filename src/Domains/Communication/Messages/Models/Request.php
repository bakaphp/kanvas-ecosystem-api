<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Communication\Messages\Enums\OperationKindEnum;
use Kanvas\Communication\Models\BaseModel;
use Kanvas\Guild\Customers\Models\People;

/**
 * What a caller asked to be said, written before any vendor is called — so a crash between the
 * decision to send and the send leaves a row to resume from rather than a message nobody remembers.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int|null $users_id
 * @property OperationKindEnum $kind
 * @property int|null $people_id
 * @property People|null $people
 * @property array $content
 * @property array|null $sender
 * @property int|null $reply_to_message_id
 * @property int|null $filed_message_id
 */
class Request extends BaseModel
{
    use UuidTrait;

    protected $table = 'communication_requests';

    protected $attributes = [
        'is_deleted' => 0,
    ];

    protected function casts(): array
    {
        return [
            'kind' => OperationKindEnum::class,
            'content' => Json::class,
            'sender' => Json::class,
            'channels' => Json::class,
            'is_deleted' => 'boolean',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'request_id');
    }

    public function people(): BelongsTo
    {
        return $this->belongsTo(People::class, 'people_id');
    }
}
