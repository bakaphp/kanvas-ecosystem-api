<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

/**
 * The file_name / content / file_url / filesystem_id set every upload_file_to_* tool exposes. Pairs
 * with AttachesFileToEntity, which consumes exactly these; the Laravel-AI side declares the same set
 * as JSON schema in HasFileUploadToolSchema.
 */
trait HasFileUploadToolProperties
{
    /**
     * @return array<int, ToolProperty>
     */
    protected function fileUploadProperties(string $exampleFileName): array
    {
        return [
            new ToolProperty(
                name: 'file_name',
                type: PropertyType::STRING,
                description: 'File name to store it under, including extension — e.g. "' . $exampleFileName . '". '
                    . 'Defaults to the URL\'s own file name, or agent-document.md for inline content.',
                required: false,
            ),
            new ToolProperty(
                name: 'content',
                type: PropertyType::STRING,
                description: 'The full text of the document to store (markdown, plain text, CSV or JSON). Use this '
                    . 'when you are the one writing the document. Mutually exclusive with file_url and filesystem_id.',
                required: false,
            ),
            new ToolProperty(
                name: 'file_url',
                type: PropertyType::STRING,
                description: 'A public URL to download the file from, when it already exists somewhere outside '
                    . 'Kanvas. Mutually exclusive with content and filesystem_id.',
                required: false,
            ),
            new ToolProperty(
                name: 'filesystem_id',
                type: PropertyType::INTEGER,
                description: 'The id of a file already stored in Kanvas — the filesystem_id from an "Attached file '
                    . 'on this message" marker, or one a list/upload tool returned. Attaches that file as it is, '
                    . 'no re-upload. Mutually exclusive with content and file_url.',
                required: false,
            ),
        ];
    }
}
