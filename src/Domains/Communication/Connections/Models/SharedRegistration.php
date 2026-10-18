<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Communication\Models\BaseModel;

/**
 * A vendor resource several connections lean on — a Messaging Service's inbound URL. Owned by the
 * vendor account and resource, so whichever integration row provisions it, there is one record and
 * one lock.
 *
 * @property int $id
 * @property string $provider
 * @property string $provider_account_key
 * @property string $resource_type
 * @property string $resource_key
 * @property string|null $registered_value
 * @property array|null $external_ids
 * @property string $status
 */
class SharedRegistration extends BaseModel
{
    public const string ACTIVE = 'active';
    public const string RELEASED = 'released';

    protected $table = 'communication_shared_registrations';

    protected function casts(): array
    {
        return [
            'external_ids' => Json::class,
            'released_at' => 'datetime',
        ];
    }

    public function dependents(): HasMany
    {
        return $this->hasMany(SharedDependent::class, 'registration_id');
    }

    public function liveDependents(): HasMany
    {
        return $this->dependents()->whereNull('released_at');
    }

    public function lockKey(): string
    {
        return self::lockKeyFor($this->provider, $this->provider_account_key, $this->resource_type . ':' . $this->resource_key);
    }

    public static function lockKeyFor(string $provider, string $account, string $resource): string
    {
        return "communication:shared:{$provider}:{$account}:{$resource}";
    }
}
