<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Models;

use Baka\Traits\KanvasModelTrait;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Scribe\ElectronicInvoicing\Exceptions\ImmutableFiscalRecordException;
use Override;

/**
 * Lean base for the fiscal tables: no soft delete, no custom fields. A fiscal row is a tax record kept for the
 * authority's retention period, so removing one is refused outright rather than hidden behind a flag.
 */
abstract class FiscalBaseModel extends EloquentModel
{
    use KanvasModelTrait;

    protected $connection = 'accounting';

    protected $guarded = ['id'];

    #[Override]
    public function delete(): never
    {
        throw new ImmutableFiscalRecordException(class_basename(static::class) . ' rows are tax records and are never deleted.');
    }

    public function softDelete(): never
    {
        $this->delete();
    }
}
