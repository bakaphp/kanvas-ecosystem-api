<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Models;

use Baka\Casts\Json;
use Baka\Traits\NoCompanyRelationshipTrait;
use Baka\Traits\UuidTrait;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Taxes\Exceptions\IllegalTaxTransition;

class OrderTaxSnapshot extends BaseModel
{
    use CascadeSoftDeletes;
    use NoCompanyRelationshipTrait;
    use UuidTrait;

    protected $table = 'order_tax_snapshots';
    protected $guarded = [];

    protected array $cascadeDeletes = ['components'];

    protected function casts(): array
    {
        return [
            'checkout_payload' => Json::class,
            'metadata' => Json::class,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(OrderTaxComponent::class, 'snapshot_id', 'id');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(TaxOperation::class, 'snapshot_id', 'id');
    }

    public function checkoutCompany(): ?Companies
    {
        if ($this->order !== null) {
            return $this->order->company;
        }

        $companyId = $this->checkout_payload['order_context']['companies_id'] ?? null;

        return $companyId !== null ? Companies::find((int) $companyId) : null;
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
