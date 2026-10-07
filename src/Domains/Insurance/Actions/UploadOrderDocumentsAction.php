<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Baka\Http\SafeUrlFetcher;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Insurance\Contracts\InspectionProviderInterface;
use Kanvas\Insurance\DataTransferObject\DocumentUploadResult;
use Kanvas\Insurance\DataTransferObject\InsuranceDocument;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceDocumentTypeEnum;
use Kanvas\Souk\Orders\Models\Order;
use Throwable;

class UploadOrderDocumentsAction
{
    public function __construct(
        protected Order $order,
        protected InspectionProviderInterface $provider,
    ) {
    }

    public function execute(): DocumentUploadResult
    {
        $alreadyUploaded = $this->uploadedIds();
        $pending = $this->pendingFiles($alreadyUploaded);

        if ($pending === []) {
            return new DocumentUploadResult(success: true, message: 'No new documents to upload', uploaded: 0);
        }

        $tempPaths = [];

        try {
            $documents = [];

            foreach ($pending as $entity) {
                $tempPath = $this->download($entity);
                $tempPaths[] = $tempPath;
                $documents[] = new InsuranceDocument(InsuranceDocumentTypeEnum::from($entity->field_name), $tempPath);
            }

            $result = $this->provider->uploadDocuments($this->order, $documents);
        } finally {
            foreach ($tempPaths as $tempPath) {
                @unlink($tempPath);
            }
        }

        if ($result->success) {
            $uploaded = [...$alreadyUploaded, ...array_map(fn (FilesystemEntities $e): int => $e->filesystem_id, $pending)];
            $this->order->set(InsuranceCustomFieldEnum::UPLOADED_DOCUMENTS->value, implode(',', array_unique($uploaded)));
        }

        return $result;
    }

    /**
     * @return list<int>
     */
    protected function uploadedIds(): array
    {
        $stored = trim((string) $this->order->get(InsuranceCustomFieldEnum::UPLOADED_DOCUMENTS->value));

        return $stored === '' ? [] : array_map(intval(...), explode(',', $stored));
    }

    /**
     * The field name is the document type, so an unrelated attachment on the order
     * is never forwarded to the insurer.
     *
     * @param list<int> $alreadyUploaded
     *
     * @return list<FilesystemEntities>
     */
    protected function pendingFiles(array $alreadyUploaded): array
    {
        $types = array_map(fn (InsuranceDocumentTypeEnum $type): string => $type->value, InsuranceDocumentTypeEnum::cases());

        return array_values(array_filter(
            $this->order->getFiles()->all(),
            fn (FilesystemEntities $entity): bool => $entity->filesystem !== null
                && in_array($entity->field_name, $types, true)
                && ! in_array((int) $entity->filesystem_id, $alreadyUploaded, true)
        ));
    }

    protected function download(FilesystemEntities $entity): string
    {
        $file = $entity->filesystem;
        $extension = pathinfo($file->name, PATHINFO_EXTENSION);
        $base = tempnam(sys_get_temp_dir(), 'ins_');
        $path = $extension !== '' ? $base . '.' . $extension : $base;

        if ($path !== $base) {
            @unlink($base);
        }

        try {
            file_put_contents($path, SafeUrlFetcher::fetch($file->url));
        } catch (Throwable $e) {
            @unlink($path);

            throw $e;
        }

        return $path;
    }
}
