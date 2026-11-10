<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A DR ANECF batch: one request annulling unused numbers, answered per line because DGII can annul some
 * ranges and refuse others.
 *
 * @property int $id
 * @property string $environment
 * @property string $status
 */
class FiscalRangeAnnulment extends FiscalBaseModel
{
    protected $table = 'fiscal_range_annulments';

    protected function casts(): array
    {
        return [
            'payload' => Json::class,
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FiscalRangeAnnulmentLine::class, 'fiscal_range_annulment_id');
    }
}
