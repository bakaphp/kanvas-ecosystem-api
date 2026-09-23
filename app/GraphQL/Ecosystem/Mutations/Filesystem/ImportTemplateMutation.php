<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Filesystem;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Imports\Actions\CreateImportSourceAction;
use Kanvas\Imports\Actions\CreateMapperFromTemplateAction;
use Kanvas\Imports\DataTransferObject\ImportSourceData;
use Kanvas\Imports\DataTransferObject\ImportTemplate;
use Kanvas\Imports\DataTransferObject\MapperFromTemplate;
use Kanvas\Imports\Enums\ImportTemplateEnum;
use Kanvas\Imports\Models\ImportSource;

class ImportTemplateMutation
{
    use ResolvesActingContext;

    public function createMapper(mixed $rootValue, array $request): FilesystemMapper
    {
        return $this->mapper($this->template($request), $request);
    }

    public function createImportSource(mixed $rootValue, array $request): ImportSource
    {
        $ctx = $this->actingContext();
        $template = $this->template($request);

        return new CreateImportSourceAction(
            ImportSourceData::from(
                $ctx->app,
                $ctx->user->getCurrentBranch(),
                [...$template->sourceInputDefaults(), ...$request['input']],
                $this->mapper($template, $request)
            )
        )->execute();
    }

    private function mapper(ImportTemplate $template, array $request): FilesystemMapper
    {
        $ctx = $this->actingContext();

        return new CreateMapperFromTemplateAction(
            new MapperFromTemplate(
                template: $template,
                app: $ctx->app,
                branch: $ctx->user->getCurrentBranch(),
                user: $ctx->user,
                options: (array) ($request['options'] ?? []),
            )
        )->execute();
    }

    private function template(array $request): ImportTemplate
    {
        return ImportTemplateEnum::from($request['template'])->template();
    }
}
