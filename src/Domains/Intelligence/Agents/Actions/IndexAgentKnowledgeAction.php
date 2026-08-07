<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Kanvas\Intelligence\Agents\DataTransferObject\AgentKnowledgeDocument;
use Kanvas\Intelligence\Agents\Services\AgentTypesenseKnowledgeService;

/**
 * Splits a document into paragraph-sized chunks, embeds each one, and stores
 * them in Typesense via AgentTypesenseKnowledgeService — the same store the
 * Laravel-AI search tool reads from. Deletes any chunks previously stored
 * under the same source first, so re-ingesting an updated document replaces
 * old passages instead of piling up duplicates alongside them.
 */
class IndexAgentKnowledgeAction
{
    private const int MAX_CHUNK_LENGTH = 1000;

    public function __construct(private readonly AgentKnowledgeDocument $data)
    {
    }

    public function execute(): int
    {
        $service = new AgentTypesenseKnowledgeService($this->data->app);
        $chunks = $this->chunk($this->data->content);

        $service->deleteBySource($this->data->sourceType, $this->data->sourceName);

        foreach ($chunks as $index => $chunk) {
            $service->store(
                id: "{$this->data->sourceType}:{$this->data->sourceName}:{$index}",
                content: $chunk,
                embedding: $service->embed($chunk),
                sourceType: $this->data->sourceType,
                sourceName: $this->data->sourceName,
            );
        }

        return count($chunks);
    }

    /**
     * Splits on blank lines (paragraph breaks), packing consecutive paragraphs
     * together until the next one would push a chunk past $maxLength. A single
     * paragraph longer than $maxLength is kept whole rather than cut mid-word.
     *
     * @return array<int, string>
     */
    private function chunk(string $content, int $maxLength = self::MAX_CHUNK_LENGTH): array
    {
        $content = trim($content);

        if ($content === '') {
            return [];
        }

        $paragraphs = preg_split('/\n{2,}/', $content) ?: [$content];

        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if ($current !== '' && strlen($current) + strlen($paragraph) + 2 > $maxLength) {
                $chunks[] = $current;
                $current = '';
            }

            $current = $current === '' ? $paragraph : "{$current}\n\n{$paragraph}";
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
