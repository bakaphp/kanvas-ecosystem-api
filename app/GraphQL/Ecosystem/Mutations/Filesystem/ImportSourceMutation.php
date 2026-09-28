<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Filesystem;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Imports\Actions\CreateImportSourceAction;
use Kanvas\Imports\Actions\RunImportSourceAction;
use Kanvas\Imports\Actions\UpdateImportSourceAction;
use Kanvas\Imports\DataTransferObject\ImportSourceData;
use Kanvas\Imports\Models\ImportSource;

class ImportSourceMutation
{
    use ResolvesActingContext;

    public function create(mixed $rootValue, array $request): ImportSource
    {
        $ctx = $this->actingContext();

        return new CreateImportSourceAction(
            ImportSourceData::from($ctx->app, $ctx->user->getCurrentBranch(), $request['input'])
        )->execute();
    }

    public function update(mixed $rootValue, array $request): ImportSource
    {
        $source = $this->source((int) $request['id']);

        return new UpdateImportSourceAction(
            $source,
            ImportSourceData::forUpdate($source, $request['input'])
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        return $this->source((int) $request['id'])->softDelete();
    }

    public function dryRun(mixed $rootValue, array $request): array
    {
        $result = new RunImportSourceAction(
            source: $this->source((int) $request['id']),
            dryRun: true,
            sampleSize: max(1, min(50, (int) ($request['limit'] ?? 5))),
        )->execute();

        return [
            'status' => $result->status->value,
            'message' => $result->message,
            'files' => $result->files,
            'rows' => $result->rows,
            'skipped_rows' => $result->skippedRows,
            'sample' => $result->sample,
        ];
    }

    public function run(mixed $rootValue, array $request): ImportSource
    {
        $source = $this->source((int) $request['id']);
        $source->queueRun();

        return $source;
    }

    private function source(int $id): ImportSource
    {
        $ctx = $this->actingContext();

        /** @var ImportSource $source */
        $source = ImportSource::getByIdFromCompanyApp($id, $ctx->company, $ctx->app);

        return $source;
    }
}
