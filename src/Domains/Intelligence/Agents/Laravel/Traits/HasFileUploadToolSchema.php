<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Traits;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Laravel\Ai\Tools\Request;

/**
 * The file_name / content / file_url triplet every upload_file_to_* tool exposes, plus the handle()
 * body that feeds it to AttachesFileToEntity and encodes the result. The Neuron side declares the
 * same triplet as ToolProperty in HasFileUploadToolProperties.
 */
trait HasFileUploadToolSchema
{
    /**
     * @return array<string, mixed>
     */
    protected function fileUploadSchema(JsonSchema $schema, string $exampleFileName): array
    {
        return [
            'file_name' => $schema
                ->string()
                ->description(
                    'File name to store it under, including extension — e.g. "' . $exampleFileName . '". '
                    . 'Defaults to the URL\'s own file name, or agent-document.md for inline content.'
                ),
            'content' => $schema
                ->string()
                ->description(
                    'The full text of the document to store (markdown, plain text, CSV or JSON). Use this when you '
                    . 'are the one writing the document. Mutually exclusive with file_url.'
                ),
            'file_url' => $schema
                ->string()
                ->description(
                    'A public URL to download the file from, when it already exists somewhere. Mutually exclusive '
                    . 'with content.'
                ),
        ];
    }

    /**
     * @param array<string, mixed> $identity keys naming the entity in the response, e.g. ['lead_id' => 12]
     */
    protected function uploadFromRequest(
        Request $request,
        EloquentModel $entity,
        string $entityLabel,
        array $identity,
    ): string {
        return (string) json_encode([
            ...$identity,
            ...$this->attachFileToEntity(
                entity: $entity,
                entityLabel: $entityLabel,
                fileUrl: (string) $request->string('file_url'),
                content: (string) $request->string('content'),
                fileName: (string) $request->string('file_name'),
            ),
        ], JSON_PRETTY_PRINT);
    }
}
