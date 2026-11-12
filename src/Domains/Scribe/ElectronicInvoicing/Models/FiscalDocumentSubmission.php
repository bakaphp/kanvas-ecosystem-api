<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalSubmissionStatusEnum;

/**
 * One send. Written and committed before the provider is called, so a crash mid-call leaves a `sending` row
 * the poller can resolve instead of a send nobody knows happened.
 *
 * `system_ref` is the systemRef this attempt went out with. WebPOS answers a repeated systemRef with the status
 * it already holds (`wasDuplicated`), so a resend after a reusable DGII rejection under the row's own reference
 * would only ever read back that rejection; each attempt gets its own, and a status query reuses the attempt's.
 * It is short and alphanumeric because WebPOS forwards it as DGII's NumeroFacturaInterna (20 characters) and
 * prints it on the e-CF. Attempts sent before the column existed went out under their `uuid` and still answer
 * to it.
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $system_ref
 * @property int $fiscal_document_id
 * @property int $attempt
 * @property FiscalSubmissionStatusEnum $status
 * @property string|null $provider
 * @property string|null $provider_ref
 * @property array<int, string>|null $messages
 * @property bool|null $authority_number_consumed
 * @property Carbon|null $sent_at
 * @property Carbon|null $responded_at
 * @property int|null $request_file_id
 * @property string|null $request_sha256
 * @property int|null $response_file_id
 * @property string|null $response_sha256
 * @property FiscalDocument $fiscalDocument
 */
class FiscalDocumentSubmission extends FiscalBaseModel
{
    use UuidTrait;

    public const int SYSTEM_REF_LENGTH = 16;

    protected $table = 'fiscal_document_submissions';

    protected function casts(): array
    {
        return [
            'status' => FiscalSubmissionStatusEnum::class,
            'codes' => Json::class,
            'messages' => Json::class,
            'authority_number_consumed' => 'boolean',
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'fiscal_document_id');
    }

    public static function newSystemRef(): string
    {
        return Str::upper(Str::random(self::SYSTEM_REF_LENGTH));
    }

    public function generateSystemRefIfMissing(): static
    {
        $this->system_ref ??= self::newSystemRef();

        return $this;
    }

    public function systemRef(): string
    {
        return $this->system_ref ?? $this->uuid;
    }
}
