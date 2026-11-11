<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $fiscal_range_annulment_id
 * @property string $document_type
 * @property int $range_from
 * @property int $range_to
 * @property string $result
 * @property string|null $message
 */
class FiscalRangeAnnulmentLine extends FiscalBaseModel
{
    public const string PENDING = 'pending';
    public const string ANNULLED = 'annulled';
    public const string REFUSED = 'refused';

    protected $table = 'fiscal_range_annulment_lines';

    protected function casts(): array
    {
        return [
            'range_from' => 'integer',
            'range_to' => 'integer',
        ];
    }

    public function annulment(): BelongsTo
    {
        return $this->belongsTo(FiscalRangeAnnulment::class, 'fiscal_range_annulment_id');
    }
}
