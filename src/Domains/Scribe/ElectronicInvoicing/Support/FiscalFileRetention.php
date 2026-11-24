<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Scribe\Bills\Actions\CreateBillFromReceivedEcfAction;
use Kanvas\Scribe\ElectronicInvoicing\Actions\ApplyFiscalAnswerAction;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalRegimeInterface;
use Kanvas\Scribe\ElectronicInvoicing\Exceptions\ImmutableFiscalRecordException;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocumentEvent;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocumentSubmission;

/**
 * The legal copies of a fiscal document (the accepted PDF, a vendor's signed XML, each attempt's request and
 * response) must outlive the authority's retention period. guardFilesystemEvents() hangs the checks on the
 * Filesystem models' delete events at boot, so every detach and delete path is refused, not only the mutations.
 */
final class FiscalFileRetention
{
    public const array FIELD_NAMES = [
        ApplyFiscalAnswerAction::PDF_FIELD_NAME,
        FiscalRegimeInterface::INVOICE_PDF_FIELD,
        CreateBillFromReceivedEcfAction::XML_FIELD_NAME,
    ];

    /**
     * softDeleting is Kanvas's own event (KanvasModelTrait::softDelete), so it has no Eloquent static registrar;
     * the dispatcher key is what fireModelEvent() dispatches. A listener that throws aborts before the row changes.
     */
    public static function guardFilesystemEvents(): void
    {
        foreach (['softDeleting', 'deleting'] as $event) {
            Event::listen(
                "eloquent.{$event}: " . FilesystemEntities::class,
                static fn (FilesystemEntities $fileEntity) => self::assertDetachable($fileEntity)
            );
            Event::listen(
                "eloquent.{$event}: " . Filesystem::class,
                static fn (Filesystem $file) => self::assertDeletable($file)
            );
        }
    }

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
