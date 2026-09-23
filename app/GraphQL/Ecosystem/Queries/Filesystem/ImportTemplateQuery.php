<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Filesystem;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Imports\Enums\ImportTemplateEnum;

class ImportTemplateQuery
{
    use ResolvesActingContext;

    public function all(mixed $rootValue, array $request): array
    {
        return array_map(
            function (ImportTemplateEnum $case) {
                $template = $case->template();

                return [
                    'key' => $case->value,
                    'name' => $template->name,
                    'description' => $template->description,
                    'file_header' => $template->fileHeader,
                    'required_columns' => $template->requiredColumns,
                    'options' => $template->options,
                    'source_defaults' => $template->sourceDefaults,
                ];
            },
            ImportTemplateEnum::cases()
        );
    }

    public function checkHeader(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();

        /** @var Filesystem $filesystem */
        $filesystem = Filesystem::getByIdFromCompanyApp((int) $request['filesystem_id'], $ctx->company, $ctx->app);
        $localPath = new FilesystemServices($ctx->app, $ctx->company)->getFileLocalPath($filesystem);

        return ImportTemplateEnum::from($request['template'])->template()->compareHeaderOfFile($localPath);
    }
}
