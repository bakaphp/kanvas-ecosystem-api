<?php

declare(strict_types=1);

namespace Kanvas\Souk\Cart\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Kanvas\Souk\Models\BaseModel;
use Override;

/**
 * Class Cart
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int|null $users_id
 * @property string|null $cart_session_id
 * @property string|null $email
 * @property float|null $amount
 * @property string $currency
 * @property string $status
 * @property array|null $metadata
 * @property bool $is_deleted
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class Cart extends BaseModel
{
    use UuidTrait;

    protected $table = 'carts';

    protected $fillable = [
        'uuid',
        'apps_id',
        'companies_id',
        'users_id',
        'session_id',
        'email',
        'amount',
        'currency',
        'status',
        'notification_count',
        'metadata',
        'items',
        'conditions',
    ];

    protected $attributes = [
        'currency' => 'usd',
        'status' => 'pending',
        'is_deleted' => 0,
    ];

    #[Override]
    public function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'metadata' => Json::class,
            'is_deleted' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'items' => Json::class,
            'conditions' => Json::class,
        ];
    }
}
