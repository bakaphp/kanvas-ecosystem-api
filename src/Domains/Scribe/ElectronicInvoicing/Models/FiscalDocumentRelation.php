<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Scribe\ElectronicInvoicing\Exceptions\ImmutableFiscalRecordException;

/**
 * `related_reference` is always filled (the referenced number as the authority knows it); the FK only when the
 * referenced document is one of ours. A note may modify a paper NCF we never had a row for.
 *
 * @property int $fiscal_document_id
 * @property int|null $related_fiscal_document_id
 * @property string|null $related_reference
 * @property string $relation_type
 * @property string|null $regime_code
 */
class FiscalDocumentRelation extends FiscalBaseModel
{
    public const string MODIFIES = 'modifies';

    protected $table = 'fiscal_document_relations';

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableFiscalRecordException('A fiscal document relation is written once and never changed.'));
    }

    protected function casts(): array
    {
        return [
            'related_issued_at' => 'date',
            'amounts' => Json::class,
        ];
    }

    public function relatedFiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'related_fiscal_document_id');
    }
}
