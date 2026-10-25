<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Taxes\Exceptions\IllegalTaxTransition;

class OrderTaxSnapshot extends BaseModel
{
    use CascadeSoftDeletes;
    use UuidTrait;

    protected $table = 'order_tax_snapshots';
    protected $guarded = [];
    protected $keyType = 'int';
    public $incrementing = true;

    protected array $cascadeDeletes = ['components'];

    protected function casts(): array
    {
        return [
            'checkout_payload' => Json::class,
            'metadata' => Json::class,
        ];
    }

    public function components(): HasMany
    {
        return $this->hasMany(OrderTaxComponent::class, 'snapshot_id', 'id');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(TaxOperation::class, 'snapshot_id', 'id');
    }

    public function transition(string $from, string $to): void
    {
        $affected = static::query()
            ->where('id', $this->id)
            ->where('status', $from)
            ->update(['status' => $to]);

        if ($affected !== 1) {
            throw new IllegalTaxTransition(
                "Cannot transition snapshot {$this->uuid} from {$from} to {$to}"
            );
        }

        $this->status = $to;
    }
}
