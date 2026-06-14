<?php

declare(strict_types=1);

namespace Kanvas\Scribe\DocumentSequences\Models;

use Baka\Traits\KanvasAppScopesTrait;
use Baka\Traits\KanvasCompanyScopesTrait;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum;

/**
 * Atomic document-number allocator state.
 *
 * NOT extending Scribe\Models\BaseModel — this table has no apps_id+companies_id partition (it HAS them, but it's
 * not a user-facing entity with soft delete / custom fields / files). The DocumentNumberAllocator service is the
 * sole writer; never write to this table directly via Eloquent in business code.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $document_type
 * @property string $prefix
 * @property int $next_value
 */
class DocumentSequence extends EloquentModel
{
    use KanvasAppScopesTrait;
    use KanvasCompanyScopesTrait;

    protected $connection = 'accounting';
    protected $table = 'document_sequences';
    protected $guarded = [];

    protected $casts = [
        'document_type' => DocumentTypeEnum::class,
        'next_value' => 'integer',
    ];
}
