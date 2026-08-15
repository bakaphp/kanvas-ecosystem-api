<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

/**
 * The file_name / content / file_url triplet every upload_file_to_* tool exposes. Pairs with
 * AttachesFileToEntity, which consumes exactly these three; the Laravel-AI side declares the same
 * triplet as JSON schema in HasFileUploadToolSchema.
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
                    . 'when you are the one writing the document. Mutually exclusive with file_url.',
                required: false,
            ),
            new ToolProperty(
                name: 'file_url',
                type: PropertyType::STRING,
                description: 'A public URL to download the file from, when it already exists somewhere. '
                    . 'Mutually exclusive with content.',
                required: false,
            ),
        ];
    }
}
