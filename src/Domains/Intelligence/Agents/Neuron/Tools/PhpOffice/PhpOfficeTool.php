<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\PhpOffice;

use InvalidArgumentException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\PhpOffice\Generators\WordGenerator;
use Kanvas\Intelligence\Agents\Neuron\Tools\PhpOffice\Services\GeneratedDocumentStore;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Generate Office Document', category: 'productivity')]
class PhpOfficeTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'generate_office_document',
            description: 'Generates an Office document from structured content. Word is currently supported; '
                . 'Excel and PowerPoint support will be added later.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'format',
                type: PropertyType::STRING,
                description: 'Document format to generate. Currently only "word" is supported.',
                required: true,
                enum: ['word'],
            ),
            new ToolProperty(
                name: 'title',
                type: PropertyType::STRING,
                description: 'Document title.',
                required: true,
            ),
            new ToolProperty(
                name: 'html_content',
                type: PropertyType::STRING,
                description: 'Simple HTML content using h1-h6, p, strong, em, table, ul, ol, and li elements. '
                    . 'Required when format is "word".',
                required: true,
            ),
        ];
    }

    public function __invoke(string $format, string $title, string $html_content): string
    {
        $generator = match ($format) {
            'word' => new WordGenerator(),
            default => throw new InvalidArgumentException("Unsupported document format: {$format}"),
        };

        $path = $generator->generate([
            'title' => $title,
            'html_content' => $html_content,
        ]);

        $documentId = (new GeneratedDocumentStore())->remember($path);

        return json_encode([
            'document_id' => $documentId,
            'path' => $path,
            'expires_at' => now()->addDay()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);
    }
}
