<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Models;

use Baka\Casts\Json;
use Baka\Traits\KanvasAppScopesTrait;
use Baka\Traits\KanvasCompanyScopesTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestStatusEnum;

/**
 * One row per inbound accounting PDF — audit trail for the email-driven ingest path.
 *
 * Extends EloquentModel directly (not Scribe BaseModel) because this table is append-only / immutable
 * once a row is written. No soft-delete, no custom fields, no Filesystem trait.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property int $filesystem_id
 * @property string|null $content_sha256
 * @property string|null $message_id
 * @property string|null $from_email
 * @property string|null $from_name
 * @property string|null $subject
 * @property array|null $inbound_metadata
 * @property PdfIngestDocumentTypeEnum $document_type
 * @property float $confidence
 * @property array|null $extracted_payload
 * @property string|null $classification_reasoning
 * @property PdfIngestStatusEnum $status
 * @property string|null $linked_entity_type
 * @property int|null $linked_entity_id
 * @property string|null $rejected_reason
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property int|null $users_id
 */
class PdfIngestLog extends EloquentModel
{
    use KanvasAppScopesTrait;
    use KanvasCompanyScopesTrait;
    use UuidTrait;

    protected $connection = 'accounting';
    protected $table = 'pdf_ingest_log';
    protected $guarded = [];

    protected $casts = [
        'document_type' => PdfIngestDocumentTypeEnum::class,
        'status' => PdfIngestStatusEnum::class,
        'confidence' => 'float',
        'inbound_metadata' => Json::class,
        'extracted_payload' => Json::class,
        'processed_at' => 'datetime',
    ];
}
