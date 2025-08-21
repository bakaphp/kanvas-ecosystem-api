<?php

declare(strict_types=1);

namespace Kanvas\Souk\Cart\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Users\Models\Users;

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
    protected $table = 'carts';

    protected $fillable = [
        'uuid',
        'apps_id',
        'companies_id',
        'users_id',
        'cart_session_id',
        'email',
        'amount',
        'currency',
        'status',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
        'is_deleted' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'currency' => 'usd',
        'status' => 'pending',
        'is_deleted' => 0,
    ];

    /**
     * Cart belongs to app.
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    /**
     * Cart belongs to company.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     * Cart belongs to user (optional for guest carts).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }
}
