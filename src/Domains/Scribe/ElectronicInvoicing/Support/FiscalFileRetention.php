<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Support;

use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalRegimeInterface;
use Illuminate\Support\Carbon;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Scribe\Bills\Actions\CreateBillFromReceivedEcfAction;
use Kanvas\Scribe\ElectronicInvoicing\Actions\ApplyFiscalAnswerAction;
use Kanvas\Scribe\ElectronicInvoicing\Exceptions\ImmutableFiscalRecordException;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocumentEvent;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocumentSubmission;

/**
 * The legal copies of a fiscal document (the accepted PDF, a vendor's signed XML, each attempt's request and
 * response) must outlive the authority's retention period, so the Filesystem detach/delete paths ask here first.
 */
final class FiscalFileRetention
{
    public const array FIELD_NAMES = [
        ApplyFiscalAnswerAction::PDF_FIELD_NAME,
        FiscalRegimeInterface::INVOICE_PDF_FIELD,
        CreateBillFromReceivedEcfAction::XML_FIELD_NAME,
    ];

    public static function assertDetachable(FilesystemEntities $fileEntity): void
    {
        if (! in_array($fileEntity->field_name, self::FIELD_NAMES, true)) {
            return;
        }

        $entity = $fileEntity->resolveEntity();

        if ($entity === null) {
            return;
        }

        $retained = FiscalDocument::query()
            ->forEntity($entity)
            ->where('retain_until', '>=', Carbon::today()->toDateString())
            ->exists();

        if ($retained) {
            throw new ImmutableFiscalRecordException(
                "The {$fileEntity->field_name} file is a fiscal record kept for the authority's retention period and cannot be removed."
            );
        }
    }

    public static function assertDeletable(Filesystem $file): void
    {
        $fileId = $file->getKey();
        $today = Carbon::today()->toDateString();

        foreach ([FiscalDocumentSubmission::class, FiscalDocumentEvent::class] as $model) {
            $retained = $model::query()
                ->where(fn ($query) => $query->where('request_file_id', $fileId)->orWhere('response_file_id', $fileId))
                ->whereHas('fiscalDocument', fn ($query) => $query->where('retain_until', '>=', $today))
                ->exists();

            if ($retained) {
                throw new ImmutableFiscalRecordException('This file is a fiscal submission artifact kept for the retention period and cannot be deleted.');
            }
        }

        FilesystemEntities::query()
            ->where('filesystem_id', $fileId)
            ->notDeleted()
            ->get()
            ->each(fn (FilesystemEntities $fileEntity) => self::assertDetachable($fileEntity));
    }
}
