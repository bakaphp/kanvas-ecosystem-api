<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentTerms\Models;

use Baka\Casts\Json;
use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\UuidTrait;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Models\BaseModel;

/**
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property string $name
 * @property int $net_days
 * @property int|null $discount_days
 * @property float|null $discount_pct
 * @property bool $is_default
 * @property array|null $metadata
 * @property bool $is_deleted
 * @property int|null $users_id
 */
class PaymentTerm extends BaseModel
{
    use UuidTrait;

    protected $table = 'payment_terms';
    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
        'is_deleted' => 'boolean',
        'discount_pct' => 'float',
        'metadata' => Json::class,
    ];

    /**
     * The term a new invoice, SOW payment schedule or billing schedule falls back to when nothing names one; null
     * leaves them due on receipt.
     */
    public static function defaultFor(AppInterface $app, CompanyInterface $company): ?self
    {
        return self::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_default', true)
            ->notDeleted()
            ->first();
    }

    public function computeDueDate(Carbon $issuedDate): Carbon
    {
        return $issuedDate->copy()->addDays($this->net_days);
    }

    public function computeDiscountDeadline(Carbon $issuedDate): ?Carbon
    {
        if ($this->discount_days === null) {
            return null;
        }

        return $issuedDate->copy()->addDays($this->discount_days);
    }
}
